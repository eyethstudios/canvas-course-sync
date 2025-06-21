
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
                url: window.ccsNonces ? (typeof ccsAjax !== 'undefined' ? ccsAjax.ajaxUrl : ajaxurl) : ajaxurl,
                type: 'POST',
                data: {
                    action: 'ccs_get_courses',
                    nonce: window.ccsNonces ? window.ccsNonces.get_courses : (typeof ccsAjax !== 'undefined' ? ccsAjax.getCoursesNonce : '')
                },
                success: function(response) {
                    console.log('CCS Courses: Get courses response:', response);
                    
                    button.prop('disabled', false);
                    loadingSpinner.hide();
                    
                    if (response.success && response.data) {
                        const courses = response.data;
                        console.log('CCS Courses: Received ' + courses.length + ' courses');
                        
                        if (courses.length === 0) {
                            courseList.html('<div class="notice notice-info"><p>No courses found in Canvas.</p></div>');
                        } else {
                            renderCourseList(courses, courseList);
                        }
                        
                        // Show the courses wrapper with action buttons
                        coursesWrapper.show();
                        console.log('CCS Courses: Courses wrapper shown');
                        
                    } else {
                        const errorMsg = response.data && response.data.message ? response.data.message : (response.data || 'Unknown error occurred');
                        courseList.html('<div class="notice notice-error"><p>Error loading courses: ' + errorMsg + '</p></div>');
                        coursesWrapper.show(); // Show wrapper even on error so user can retry
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
    
    // Render course list
    function renderCourseList(courses, container) {
        console.log('CCS Courses: Rendering ' + courses.length + ' courses');
        
        let html = '<div class="ccs-courses-header" style="margin-bottom: 15px; padding: 10px; background: #f0f0f1; border-left: 4px solid #0073aa;">';
        html += '<strong>Found ' + courses.length + ' course(s) in Canvas</strong>';
        html += '<p style="margin: 5px 0 0 0; font-size: 13px; color: #666;">Select courses to sync or omit from auto-sync.</p>';
        html += '</div>';
        
        html += '<table class="wp-list-table widefat fixed striped" style="margin-bottom: 20px;">';
        html += '<thead><tr>';
        html += '<th scope="col" class="manage-column" style="width: 40px;"><input type="checkbox" id="ccs-select-all-checkbox"></th>';
        html += '<th scope="col" class="manage-column">Course Name</th>';
        html += '<th scope="col" class="manage-column" style="width: 100px;">Canvas ID</th>';
        html += '<th scope="col" class="manage-column" style="width: 120px;">Status</th>';
        html += '</tr></thead>';
        html += '<tbody>';
        
        courses.forEach(function(course, index) {
            const courseId = course.id || 0;
            const courseName = course.name || 'Unnamed Course';
            const status = course.status_label || 'Unknown';
            const isOmitted = course.is_omitted || false;
            
            let statusClass = 'notice-info';
            let statusText = status;
            
            if (isOmitted) {
                statusClass = 'notice-warning';
                statusText = 'Omitted';
            } else if (course.status === 'synced') {
                statusClass = 'notice-success';
                statusText = 'Synced';
            } else if (course.status === 'exists') {
                statusClass = 'notice-alt';
                statusText = 'Exists';
            }
            
            html += '<tr>';
            html += '<td><input type="checkbox" class="ccs-course-checkbox" value="' + courseId + '" data-course-name="' + escapeHtml(courseName) + '"></td>';
            html += '<td><strong>' + escapeHtml(courseName) + '</strong></td>';
            html += '<td>' + courseId + '</td>';
            html += '<td><span class="notice ' + statusClass + '" style="padding: 2px 8px; margin: 0; display: inline-block;">' + statusText + '</span></td>';
            html += '</tr>';
        });
        
        html += '</tbody></table>';
        
        container.html(html);
        
        // Add select all functionality for the table header checkbox
        $('#ccs-select-all-checkbox').on('change', function() {
            $('.ccs-course-checkbox').prop('checked', $(this).prop('checked'));
        });
        
        console.log('CCS Courses: Course list rendered successfully');
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
