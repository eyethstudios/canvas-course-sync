
/**
 * Course management functionality
 */
export function initCourseManager($) {
    // Load courses button handler
    $('#ccs-load-courses').on('click', function() {
        const button = $(this);
        const courseList = $('#ccs-course-list');
        const loadingText = $('#ccs-loading-courses');
        const coursesWrapper = $('#ccs-courses-wrapper');
        
        button.attr('disabled', true);
        loadingText.show();
        courseList.html('');
        
        $.ajax({
            url: ccsData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ccs_get_courses',
                nonce: ccsData.getCoursesNonce
            },
            success: function(response) {
                console.log('Full AJAX response:', response);
                
                if (response.success && Array.isArray(response.data)) {
                    // Debug the data received from the API
                    console.log('Courses before sorting:', response.data);
                    
                    // Sort courses by creation date (most recent first)
                    const sortedCourses = response.data.sort((a, b) => {
                        return new Date(b.created_at || 0) - new Date(a.created_at || 0);
                    });
                    
                    console.log('Sorted courses:', sortedCourses);
                    
                    let html = '<div class="ccs-select-all">' +
                        '<label>' +
                        '<input type="checkbox" id="ccs-select-all-checkbox" checked> ' +
                        'Select/Deselect All</label>' +
                        '</div>';
                        
                    sortedCourses.forEach(function(course) {
                        let statusClass = '';
                        let statusText = '';
                        let checkboxChecked = 'checked';
                        
                        console.log('Processing course:', course.name, 'exists_in_wp:', course.exists_in_wp, 'match_type:', course.match_type);
                        
                        // Check if course exists in WordPress
                        if (course.exists_in_wp === true || course.exists_in_wp === 'true') {
                            statusClass = 'ccs-course-exists';
                            if (course.match_type === 'canvas_id') {
                                statusText = ' <span class="ccs-status-badge ccs-exists-canvas-id">(Already synced)</span>';
                            } else if (course.match_type === 'title') {
                                statusText = ' <span class="ccs-status-badge ccs-exists-title">(Title exists in WP)</span>';
                            } else {
                                // Fallback for any other match type
                                statusText = ' <span class="ccs-status-badge ccs-exists-title">(Already exists)</span>';
                            }
                            checkboxChecked = ''; // Don't check existing courses by default
                        }
                        
                        html += '<div class="ccs-course-item ' + statusClass + '">' +
                            '<label>' +
                            '<input type="checkbox" class="ccs-course-checkbox" ' +
                            'value="' + course.id + '" ' + checkboxChecked + '> ' +
                            course.name + statusText + '</label>' +
                            '</div>';
                    });
                    
                    courseList.html(html);
                    coursesWrapper.show();
                } else {
                    const errorMessage = response.data || 'Error loading courses. Please try again.';
                    courseList.html('<p class="error">Error loading courses: ' + errorMessage + '</p>');
                    coursesWrapper.show();
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error:', xhr, status, error);
                courseList.html('<p class="error">Connection error occurred. Please try again.</p>');
                coursesWrapper.show();
            },
            complete: function() {
                button.attr('disabled', false);
                loadingText.hide();
            }
        });
    });

    // Handle select all checkbox
    $(document).on('change', '#ccs-select-all-checkbox', function() {
        $('.ccs-course-checkbox').prop('checked', $(this).prop('checked'));
    });
}
