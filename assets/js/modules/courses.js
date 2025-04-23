
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
        console.log('Load courses button clicked');
        console.log('Using nonce:', ccsData.getCoursesNonce);
        
        $.ajax({
            url: ccsData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ccs_get_courses',
                nonce: ccsData.getCoursesNonce
            },
            success: function(response) {
                console.log('Courses response:', response);
                if (response.success && Array.isArray(response.data)) {
                    let html = '<div class="ccs-select-all">' +
                        '<label>' +
                        '<input type="checkbox" id="ccs-select-all-checkbox" checked> ' +
                        'Select/Deselect All</label>' +
                        '</div>';
                        
                    response.data.forEach(function(course) {
                        html += '<div class="ccs-course-item">' +
                            '<label>' +
                            '<input type="checkbox" class="ccs-course-checkbox" ' +
                            'value="' + course.id + '" checked> ' +
                            course.name + '</label>' +
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
                console.error('AJAX error:', error);
                console.error('AJAX status:', status);
                console.error('AJAX response:', xhr.responseText);
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
