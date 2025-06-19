
/**
 * Course management functionality
 */
export function initCourseManager($) {
    // Load courses button handler - support both old and new IDs
    $('#ccs-load-courses, #ccs-get-courses').on('click', function() {
        const button = $(this);
        const courseList = $('#ccs-course-list, #ccs-courses-list');
        const loadingText = $('#ccs-loading-courses');
        const coursesWrapper = $('#ccs-courses-wrapper');
        
        button.attr('disabled', true);
        if (loadingText.length) {
            loadingText.show();
        } else {
            button.text('Loading...');
        }
        courseList.html('');
        
        $.ajax({
            url: window.ajaxurl || ccsAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ccs_get_courses',
                nonce: window.ccsNonces?.get_courses || ccsAjax.getCoursesNonce
            },
            success: function(response) {
                console.log('Full AJAX response:', response);
                
                if (response.success && Array.isArray(response.data)) {
                    console.log('Courses received:', response.data);
                    
                    // Sort courses by status priority and then by creation date
                    const sortedCourses = response.data.sort((a, b) => {
                        const statusPriority = { 'new': 1, 'exists': 2, 'synced': 3 };
                        const priorityA = statusPriority[a.status] || 999;
                        const priorityB = statusPriority[b.status] || 999;
                        
                        if (priorityA !== priorityB) {
                            return priorityA - priorityB;
                        }
                        
                        return new Date(b.created_at || 0) - new Date(a.created_at || 0);
                    });
                    
                    let html = '<div class="ccs-controls-section">' +
                        '<div class="ccs-select-all">' +
                        '<label>' +
                        '<input type="checkbox" id="ccs-select-all-checkbox" checked> ' +
                        'Select/Deselect All</label>' +
                        '</div>' +
                        '<div class="ccs-filter-controls">' +
                        '<label for="ccs-status-filter">Filter by Status: </label>' +
                        '<select id="ccs-status-filter" class="ccs-filter-dropdown">' +
                        '<option value="all">All Courses</option>' +
                        '<option value="new">New Only</option>' +
                        '<option value="exists">Title Exists Only</option>' +
                        '<option value="synced">Already Synced Only</option>' +
                        '</select>' +
                        '</div>' +
                        '<div class="ccs-sort-controls">' +
                        '<label for="ccs-sort-select">Sort by: </label>' +
                        '<select id="ccs-sort-select" class="ccs-sort-dropdown">' +
                        '<option value="status">Status (New → Existing → Synced)</option>' +
                        '<option value="name">Course Name (A-Z)</option>' +
                        '<option value="date">Creation Date (Newest First)</option>' +
                        '</select>' +
                        '</div>' +
                        '</div>';
                        
                    sortedCourses.forEach(function(course) {
                        let statusClass = '';
                        let statusText = '';
                        let checkboxChecked = 'checked';
                        
                        console.log('Processing course:', course.name, 'status:', course.status, 'status_label:', course.status_label);
                        
                        // Use the explicit status from backend
                        if (course.status === 'synced') {
                            statusClass = 'ccs-course-exists';
                            statusText = ' <span class="ccs-status-badge ccs-exists-canvas-id">(' + course.status_label + ')</span>';
                            checkboxChecked = '';
                        } else if (course.status === 'exists') {
                            statusClass = 'ccs-course-exists';
                            statusText = ' <span class="ccs-status-badge ccs-exists-title">(' + course.status_label + ')</span>';
                            checkboxChecked = '';
                        } else {
                            statusText = ' <span class="ccs-status-badge ccs-new-course">(' + course.status_label + ')</span>';
                        }
                        
                        // Add course code if available
                        let courseDisplayName = course.name;
                        if (course.course_code && course.course_code !== course.name) {
                            courseDisplayName += ' (' + course.course_code + ')';
                        }
                        
                        html += '<div class="ccs-course-item ' + statusClass + '" ' +
                            'data-course-name="' + course.name + '" ' +
                            'data-created-at="' + (course.created_at || '') + '" ' +
                            'data-status="' + course.status + '">' +
                            '<label>' +
                            '<input type="checkbox" class="ccs-course-checkbox course-checkbox" ' +
                            'value="' + course.id + '" ' + checkboxChecked + '> ' +
                            courseDisplayName + statusText + '</label>' +
                            '</div>';
                    });
                    
                    courseList.html(html);
                    if (coursesWrapper.length) {
                        coursesWrapper.show();
                    }
                    
                    // Enable sync button
                    $('#ccs-sync-selected').prop('disabled', false);
                    
                    // Show success notice if showNotice function exists
                    if (typeof showNotice === 'function') {
                        showNotice('Loaded ' + response.data.length + ' courses', 'success');
                    }
                } else {
                    const errorMessage = response.data || 'Error loading courses. Please try again.';
                    courseList.html('<p class="error">Error loading courses: ' + errorMessage + '</p>');
                    if (coursesWrapper.length) {
                        coursesWrapper.show();
                    }
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error:', xhr, status, error);
                courseList.html('<p class="error">Connection error occurred. Please try again.</p>');
                if (coursesWrapper.length) {
                    coursesWrapper.show();
                }
            },
            complete: function() {
                button.attr('disabled', false);
                if (loadingText.length) {
                    loadingText.hide();
                } else {
                    button.text('Get Courses');
                }
            }
        });
    });

    // Handle select all checkbox
    $(document).on('change', '#ccs-select-all-checkbox', function() {
        const isChecked = $(this).prop('checked');
        $('.ccs-course-checkbox:visible').prop('checked', isChecked);
    });
    
    // Handle status filter dropdown
    $(document).on('change', '#ccs-status-filter', function() {
        const filterValue = $(this).val();
        const courseItems = $('.ccs-course-item');
        
        courseItems.each(function() {
            const $item = $(this);
            const status = $item.data('status');
            
            if (filterValue === 'all' || status === filterValue) {
                $item.show();
            } else {
                $item.hide();
            }
        });
        
        // Update select all checkbox based on visible items
        updateSelectAllCheckbox();
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
                    return dateB - dateA;
                    
                case 'status':
                default:
                    const statusPriority = { 'new': 1, 'exists': 2, 'synced': 3 };
                    const priorityA = statusPriority[$a.data('status')] || 999;
                    const priorityB = statusPriority[$b.data('status')] || 999;
                    
                    if (priorityA !== priorityB) {
                        return priorityA - priorityB;
                    }
                    
                    const dateA2 = new Date($a.data('created-at') || 0);
                    const dateB2 = new Date($b.data('created-at') || 0);
                    return dateB2 - dateA2;
            }
        });
        
        // Re-append sorted items
        const courseList = $('#ccs-course-list, #ccs-courses-list');
        const controlsSection = courseList.find('.ccs-controls-section');
        
        courseList.empty();
        courseList.append(controlsSection);
        
        courseItems.forEach(function(item) {
            courseList.append(item);
        });
    });
    
    // Helper function to update select all checkbox
    function updateSelectAllCheckbox() {
        const visibleCheckboxes = $('.ccs-course-checkbox:visible');
        const checkedVisibleCheckboxes = $('.ccs-course-checkbox:visible:checked');
        const selectAllCheckbox = $('#ccs-select-all-checkbox');
        
        if (visibleCheckboxes.length === 0) {
            selectAllCheckbox.prop('indeterminate', false).prop('checked', false);
        } else if (checkedVisibleCheckboxes.length === visibleCheckboxes.length) {
            selectAllCheckbox.prop('indeterminate', false).prop('checked', true);
        } else if (checkedVisibleCheckboxes.length > 0) {
            selectAllCheckbox.prop('indeterminate', true);
        } else {
            selectAllCheckbox.prop('indeterminate', false).prop('checked', false);
        }
    }
    
    // Update select all checkbox when individual checkboxes change
    $(document).on('change', '.ccs-course-checkbox', function() {
        updateSelectAllCheckbox();
    });
}
