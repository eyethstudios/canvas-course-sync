
// Course management module for Canvas Course Sync
(function($) {
    'use strict';
    
    console.log('CCS Courses Module: Loading...');
    
    // Initialize course manager
    function initCourseManager() {
        console.log('CCS Courses Module: Initializing course manager');
        
        // Get courses button handler
        $(document).on('click', '#ccs-get-courses', function(e) {
            e.preventDefault();
            console.log('CCS Courses: Get courses button clicked');
            
            const button = $(this);
            const loadingSpinner = $('#ccs-loading-courses');
            const coursesWrapper = $('#ccs-courses-wrapper');
            const courseList = $('#ccs-course-list');
            
            // Show loading state
            button.prop('disabled', true);
            loadingSpinner.show();
            coursesWrapper.hide();
            
            // Clear previous results
            courseList.empty();
            
            $.ajax({
                url: (typeof ccsAjax !== 'undefined' && ccsAjax.ajaxUrl) ? ccsAjax.ajaxUrl : 
                     (typeof ajaxurl !== 'undefined') ? ajaxurl : '/wp-admin/admin-ajax.php',
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'ccs_get_courses',
                    nonce: (typeof ccsAjax !== 'undefined' && ccsAjax.getCoursesNonce) ? 
                           ccsAjax.getCoursesNonce : 
                           (window.ccsNonces && window.ccsNonces.get_courses) ? 
                           window.ccsNonces.get_courses : ''
                },
                success: function(response) {
                    console.log('CCS Courses: Get courses response:', response);
                    
                    button.prop('disabled', false);
                    loadingSpinner.hide();
                    
                    if (response.success && response.data) {
                        console.log('CCS Courses: Raw response data:', response.data);
                        
                        // Handle new response format with validation
                        const courses = response.data.courses || response.data;
                        const validationReport = response.data.validation_report || '';
                        const autoOmittedCount = response.data.auto_omitted_count || 0;
                        
                        console.log('CCS Courses: Parsed courses:', courses);
                        console.log('CCS Courses: Type of courses:', typeof courses);
                        console.log('CCS Courses: Is array:', Array.isArray(courses));
                        
                        console.log('CCS Courses: Received ' + courses.length + ' courses');
                        
                        if (autoOmittedCount > 0) {
                            console.log('CCS Courses: Auto-omitted ' + autoOmittedCount + ' courses not in catalog');
                        }
                        
                        // Display validation report if available
                        if (validationReport) {
                            const reportDiv = $('<div class="ccs-validation-report-wrapper" style="margin-bottom: 20px;"></div>');
                            reportDiv.html(validationReport);
                            courseList.append(reportDiv);
                        }
                        
                        if (!Array.isArray(courses) || courses.length === 0) {
                            if (!Array.isArray(courses)) {
                                console.error('CCS Courses: Expected array but got:', typeof courses, courses);
                                courseList.html('<div class="notice notice-error"><p>Invalid course data format received. Expected array but got: ' + typeof courses + '</p></div>');
                            } else {
                                courseList.html('<div class="notice notice-info"><p>No courses found in Canvas.</p></div>');
                            }
                        } else {
                            renderCourseList(courses, courseList);
                        }
                        
                        // Show the courses wrapper and action buttons
                        coursesWrapper.show();
                        $('.ccs-action-buttons').show();
                        
                        // Initialize omit functionality after courses are loaded
                        initOmitFunctionality();
                        console.log('CCS Courses: Courses wrapper, action buttons shown, and omit functionality initialized');
                        
                    } else {
                        console.error('CCS Courses: Error response:', response);
                        let errorMsg = 'Unknown error occurred';
                        
                        if (response.data) {
                            if (typeof response.data === 'string') {
                                errorMsg = response.data;
                            } else if (response.data.message) {
                                errorMsg = response.data.message;
                            } else if (typeof response.data === 'object') {
                                errorMsg = 'Server returned object: ' + JSON.stringify(response.data).substring(0, 100);
                            }
                        }
                        
                        courseList.html('<div class="notice notice-error"><p>Error loading courses: ' + errorMsg + '</p></div>');
                        coursesWrapper.show();
                    }
                },
                error: function(xhr, status, error) {
                    console.error('CCS Courses: AJAX error:', error, xhr.responseText);
                    
                    button.prop('disabled', false);
                    loadingSpinner.hide();
                    
                    let errorMessage = 'Network error occurred: ' + error;
                    if (xhr.responseText) {
                        try {
                            const parsed = JSON.parse(xhr.responseText);
                            if (parsed.data && parsed.data.message) {
                                errorMessage = 'Error: ' + parsed.data.message;
                            }
                        } catch (e) {
                            // Use default error message
                        }
                    }
                    
                    courseList.html('<div class="notice notice-error"><p>' + errorMessage + '</p></div>');
                    coursesWrapper.show();
                }
            });
        });
        
        console.log('CCS Courses Module: Course manager initialized');
    }
    
    // Initialize omit functionality
    function initOmitFunctionality() {
        console.log('CCS: Initializing omit functionality...');
        
        // Remove existing handlers to prevent duplicates
        $(document).off('click.ccs-omit');
        
        // Select/Deselect all handlers
        $(document).on('click.ccs-omit', '#ccs-select-all', function(e) {
            e.preventDefault();
            console.log('CCS: Select all clicked');
            $('.ccs-course-checkbox').prop('checked', true);
        });
        
        $(document).on('click.ccs-omit', '#ccs-deselect-all', function(e) {
            e.preventDefault();
            console.log('CCS: Deselect all clicked');
            $('.ccs-course-checkbox').prop('checked', false);
        });
        
        // Omit selected courses
        $(document).on('click.ccs-omit', '#ccs-omit-selected', function(e) {
            e.preventDefault();
            console.log('CCS: Omit selected clicked');
            
            const selectedCourses = $('.ccs-course-checkbox:checked').map(function() {
                return $(this).val();
            }).get();
            
            console.log('CCS: Selected courses for omitting:', selectedCourses);
            
            if (selectedCourses.length === 0) {
                alert('Please select at least one course to omit.');
                return;
            }
            
            if (!confirm('Are you sure you want to omit ' + selectedCourses.length + ' course(s) from future auto-syncs?')) {
                return;
            }
            
            omitCourses(selectedCourses);
        });
        
        // Restore omitted courses
        $(document).on('click.ccs-omit', '#ccs-restore-omitted', function(e) {
            e.preventDefault();
            console.log('CCS: Restore omitted clicked');
            
            if (!confirm('Are you sure you want to restore all omitted courses for future auto-syncs?')) {
                return;
            }
            
            restoreOmittedCourses();
        });
        
        console.log('CCS: Omit functionality initialized');
    }
    
    // Function to omit courses
    function omitCourses(courseIds) {
        console.log('CCS: Omitting courses:', courseIds);
        const button = $('#ccs-omit-selected');
        const originalText = button.text();
        
        button.prop('disabled', true).text('Omitting...');
        
        $.ajax({
            url: (typeof ccsAjax !== 'undefined' && ccsAjax.ajaxUrl) ? ccsAjax.ajaxUrl : 
                 (typeof ajaxurl !== 'undefined') ? ajaxurl : '/wp-admin/admin-ajax.php',
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'ccs_omit_courses',
                nonce: window.ccsOmitNonce || '',
                course_ids: courseIds
            },
            success: function(response) {
                console.log('CCS: Omit response:', response);
                button.prop('disabled', false).text(originalText);
                
                if (response.success) {
                    alert(response.data.message || 'Courses omitted successfully.');
                    // Reload courses to show updated status
                    $('#ccs-get-courses').trigger('click');
                } else {
                    alert('Error: ' + (response.data?.message || 'Unknown error'));
                }
            },
            error: function(xhr, status, error) {
                console.error('CCS: Omit error:', error, xhr.responseText);
                button.prop('disabled', false).text(originalText);
                alert('Network error. Please try again.');
            }
        });
    }
    
    // Function to restore omitted courses
    function restoreOmittedCourses() {
        console.log('CCS: Restoring omitted courses');
        const button = $('#ccs-restore-omitted');
        const originalText = button.text();
        
        button.prop('disabled', true).text('Restoring...');
        
        $.ajax({
            url: (typeof ccsAjax !== 'undefined' && ccsAjax.ajaxUrl) ? ccsAjax.ajaxUrl : 
                 (typeof ajaxurl !== 'undefined') ? ajaxurl : '/wp-admin/admin-ajax.php',
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'ccs_restore_omitted',
                nonce: window.ccsRestoreNonce || window.ccsOmitNonce || ''
            },
            success: function(response) {
                console.log('CCS: Restore response:', response);
                button.prop('disabled', false).text(originalText);
                
                if (response.success) {
                    alert(response.data.message || 'Omitted courses restored successfully.');
                    // Reload courses to show updated status
                    $('#ccs-get-courses').trigger('click');
                } else {
                    alert('Error: ' + (response.data?.message || 'Unknown error'));
                }
            },
            error: function(xhr, status, error) {
                console.error('CCS: Restore error:', error, xhr.responseText);
                button.prop('disabled', false).text(originalText);
                alert('Network error. Please try again.');
            }
        });
    }
    
    // Render course list with omitted status
    function renderCourseList(courses, container) {
        console.log('CCS Courses: Rendering ' + courses.length + ' courses');
        
        let html = '<div class="ccs-courses-header" style="margin-bottom: 15px; padding: 10px; background: #f0f0f1; border-left: 4px solid #0073aa;">';
        html += '<strong>Found ' + courses.length + ' course(s) in Canvas</strong>';
        html += '<p style="margin: 5px 0 0 0; font-size: 13px; color: #666;">Select courses to sync or manage their auto-sync status.</p>';
        html += '</div>';
        
        html += '<table class="wp-list-table widefat fixed striped" style="margin-bottom: 20px;">';
        html += '<thead><tr>';
        html += '<th scope="col" class="manage-column" style="width: 40px;"><input type="checkbox" id="ccs-select-all-checkbox"></th>';
        html += '<th scope="col" class="manage-column">Course Name</th>';
        html += '<th scope="col" class="manage-column" style="width: 100px;">Canvas ID</th>';
        html += '<th scope="col" class="manage-column" style="width: 140px;">Status</th>';
        html += '</tr></thead>';
        html += '<tbody>';
        
        courses.forEach(function(course, index) {
            const courseId = course.id || 0;
            const courseName = course.name || 'Unnamed Course';
            const isOmitted = course.is_omitted || false;
            
            let statusClass = 'notice-info';
            let statusText = 'Available';
            
            if (isOmitted) {
                statusClass = 'notice-warning';
                statusText = 'Omitted from Auto-Sync';
            } else if (course.status === 'synced') {
                statusClass = 'notice-success';
                statusText = 'Already Synced';
            } else if (course.status === 'exists') {
                statusClass = 'notice-alt';
                statusText = 'Exists in WordPress';
            }
            
            html += '<tr' + (isOmitted ? ' style="opacity: 0.7;"' : '') + '>';
            html += '<td><input type="checkbox" class="ccs-course-checkbox" value="' + courseId + '" data-course-name="' + escapeHtml(courseName) + '"></td>';
            html += '<td><strong>' + escapeHtml(courseName) + '</strong></td>';
            html += '<td>' + courseId + '</td>';
            html += '<td><span class="notice ' + statusClass + '" style="padding: 4px 8px; margin: 0; display: inline-block; font-size: 12px;">' + statusText + '</span></td>';
            html += '</tr>';
        });
        
        html += '</tbody></table>';
        
        container.html(html);
        
        // Add select all functionality for the table header checkbox
        $('#ccs-select-all-checkbox').off('change').on('change', function() {
            $('.ccs-course-checkbox').prop('checked', $(this).prop('checked'));
        });
        
        console.log('CCS Courses: Course list rendered successfully with omit status');
    }
    
    // Utility function to escape HTML
    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }
    
    // Export for global access
    window.initCourseManager = initCourseManager;
    
    console.log('CCS Courses Module: Loaded successfully');
    
})(jQuery);
