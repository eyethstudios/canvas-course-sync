
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
                    
                    // Sort courses by status priority and then by creation date
                    const sortedCourses = response.data.sort((a, b) => {
                        // Define status priority (lower number = higher priority)
                        const getStatusPriority = (course) => {
                            if (course.exists_in_wp === true || course.exists_in_wp === 'true') {
                                if (course.match_type === 'canvas_id') return 3; // Already synced
                                return 2; // Title exists in WP
                            }
                            return 1; // New course (highest priority)
                        };
                        
                        const priorityA = getStatusPriority(a);
                        const priorityB = getStatusPriority(b);
                        
                        // First sort by status priority
                        if (priorityA !== priorityB) {
                            return priorityA - priorityB;
                        }
                        
                        // Then sort by creation date (most recent first) within same status
                        return new Date(b.created_at || 0) - new Date(a.created_at || 0);
                    });
                    
                    console.log('Sorted courses:', sortedCourses);
                    
                    let html = '<div class="ccs-select-all">' +
                        '<label>' +
                        '<input type="checkbox" id="ccs-select-all-checkbox" checked> ' +
                        'Select/Deselect All</label>' +
                        '</div>';
                    
                    // Add sorting controls
                    html += '<div class="ccs-sort-controls">' +
                        '<label for="ccs-sort-select">Sort by: </label>' +
                        '<select id="ccs-sort-select" class="ccs-sort-dropdown">' +
                        '<option value="status">Status (New → Existing → Synced)</option>' +
                        '<option value="name">Course Name (A-Z)</option>' +
                        '<option value="date">Creation Date (Newest First)</option>' +
                        '</select>' +
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
                        } else {
                            // Course doesn't exist in WordPress - show "New" badge
                            statusText = ' <span class="ccs-status-badge ccs-new-course">(New)</span>';
                        }
                        
                        // Add course code if available
                        let courseDisplayName = course.name;
                        if (course.course_code && course.course_code !== course.name) {
                            courseDisplayName += ' (' + course.course_code + ')';
                        }
                        
                        html += '<div class="ccs-course-item ' + statusClass + '" data-course-name="' + course.name + '" data-created-at="' + (course.created_at || '') + '" data-status="' + (course.exists_in_wp ? (course.match_type === 'canvas_id' ? 'synced' : 'exists') : 'new') + '">' +
                            '<label>' +
                            '<input type="checkbox" class="ccs-course-checkbox" ' +
                            'value="' + course.id + '" ' + checkboxChecked + '> ' +
                            courseDisplayName + statusText + '</label>' +
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
    
    // Handle sort dropdown change
    $(document).on('change', '#ccs-sort-select', function() {
        const sortBy = $(this).val();
        const courseItems = $('.ccs-course-item').toArray();
        
        courseItems.sort(function(a, b) {
            const $a = $(a);
            const $b = $(b);
            
            switch(sortBy) {
                case 'name':
                    return $a.data('course-name').localeCompare($b.data('course-name'));
                    
                case 'date':
                    const dateA = new Date($a.data('created-at') || 0);
                    const dateB = new Date($b.data('created-at') || 0);
                    return dateB - dateA; // Newest first
                    
                case 'status':
                default:
                    const statusPriority = { 'new': 1, 'exists': 2, 'synced': 3 };
                    const priorityA = statusPriority[$a.data('status')] || 999;
                    const priorityB = statusPriority[$b.data('status')] || 999;
                    
                    if (priorityA !== priorityB) {
                        return priorityA - priorityB;
                    }
                    
                    // Secondary sort by creation date for same status
                    const dateA2 = new Date($a.data('created-at') || 0);
                    const dateB2 = new Date($b.data('created-at') || 0);
                    return dateB2 - dateA2;
            }
        });
        
        // Re-append sorted items
        const courseList = $('#ccs-course-list');
        const selectAllDiv = courseList.find('.ccs-select-all');
        const sortControlsDiv = courseList.find('.ccs-sort-controls');
        
        courseList.empty();
        courseList.append(selectAllDiv);
        courseList.append(sortControlsDiv);
        
        courseItems.forEach(function(item) {
            courseList.append(item);
        });
    });
}
