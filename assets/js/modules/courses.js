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
        
        console.log('CCS Debug: Starting AJAX request to get courses');
        console.log('CCS Debug: Using AJAX URL:', window.ajaxurl || ccsAjax.ajaxUrl);
        console.log('CCS Debug: Using nonce:', window.ccsNonces?.get_courses || ccsAjax.getCoursesNonce);
        
        $.ajax({
            url: window.ajaxurl || ccsAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ccs_get_courses',
                nonce: window.ccsNonces?.get_courses || ccsAjax.getCoursesNonce
            },
            success: function(response) {
                console.log('CCS Debug: ===== AJAX SUCCESS RESPONSE =====');
                console.log('CCS Debug: Full AJAX response:', response);
                console.log('CCS Debug: Response type:', typeof response);
                
                // Handle different response formats more robustly
                let coursesData = null;
                
                // WordPress AJAX responses can come in different formats
                if (response && response.success === true) {
                    // Standard wp_send_json_success format
                    coursesData = response.data;
                    console.log('CCS Debug: Using wp_send_json_success format');
                } else if (response && response.success === false) {
                    // Error response from wp_send_json_error
                    console.error('CCS Debug: Server returned error:', response.data);
                    courseList.html('<p class="error">Server error: ' + (response.data || 'Unknown error') + '</p>');
                    if (coursesWrapper.length) {
                        coursesWrapper.show();
                    }
                    return;
                } else if (Array.isArray(response)) {
                    // Direct array response
                    coursesData = response;
                    console.log('CCS Debug: Using direct array format');
                } else if (response && Array.isArray(response.data)) {
                    // Response with data property
                    coursesData = response.data;
                    console.log('CCS Debug: Using response.data format');
                } else {
                    // Try to extract data from any nested structure
                    console.log('CCS Debug: Attempting to find course data in response structure');
                    if (response && typeof response === 'object') {
                        // Look for arrays in the response object
                        const keys = Object.keys(response);
                        for (let key of keys) {
                            if (Array.isArray(response[key])) {
                                coursesData = response[key];
                                console.log('CCS Debug: Found array data at key:', key);
                                break;
                            }
                        }
                    }
                }
                
                console.log('CCS Debug: Final courses data:', coursesData);
                console.log('CCS Debug: Courses data type:', typeof coursesData);
                console.log('CCS Debug: Is array?', Array.isArray(coursesData));
                console.log('CCS Debug: Array length:', coursesData ? coursesData.length : 'N/A');
                
                if (!Array.isArray(coursesData)) {
                    console.error('CCS Debug: coursesData is not an array:', coursesData);
                    courseList.html('<p class="error">Invalid response format. Expected array of courses but got: ' + typeof coursesData + '</p>');
                    if (coursesWrapper.length) {
                        coursesWrapper.show();
                    }
                    return;
                }
                
                if (coursesData.length === 0) {
                    console.log('CCS Debug: Empty courses array received');
                    courseList.html('<p>No courses found after filtering. The server found courses but they may all be excluded by your filter settings.</p>');
                    if (coursesWrapper.length) {
                        coursesWrapper.show();
                    }
                    return;
                }
                
                console.log('CCS Debug: Processing ' + coursesData.length + ' courses');
                console.log('CCS Debug: First course sample:', coursesData[0]);
                
                // Sort courses by status priority and then by creation date
                const sortedCourses = coursesData.sort((a, b) => {
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
                    
                    console.log('CCS Debug: Processing course:', course.name, 'status:', course.status);
                    
                    // Use the explicit status from backend with proper labeling
                    if (course.status === 'synced') {
                        statusClass = 'ccs-course-exists';
                        statusText = ' <span class="ccs-status-badge ccs-exists-canvas-id">' + 
                                   (course.status_label || 'Already synced') + '</span>';
                        checkboxChecked = ''; // Don't pre-select already synced courses
                    } else if (course.status === 'exists') {
                        statusClass = 'ccs-course-exists';
                        statusText = ' <span class="ccs-status-badge ccs-exists-title">' + 
                                   (course.status_label || 'Title exists in WP') + '</span>';
                        checkboxChecked = ''; // Don't pre-select existing title courses
                    } else {
                        // New course
                        statusText = ' <span class="ccs-status-badge ccs-new-course">' + 
                                   (course.status_label || 'New') + '</span>';
                    }
                    
                    // Add course code if available and different from name
                    let courseDisplayName = course.name || 'Unnamed Course';
                    if (course.course_code && course.course_code !== course.name) {
                        courseDisplayName += ' (' + course.course_code + ')';
                    }
                    
                    html += '<div class="ccs-course-item ' + statusClass + '" ' +
                        'data-course-name="' + (course.name || '').replace(/"/g, '&quot;') + '" ' +
                        'data-created-at="' + (course.created_at || '') + '" ' +
                        'data-status="' + (course.status || 'new') + '">' +
                        '<label>' +
                        '<input type="checkbox" class="ccs-course-checkbox course-checkbox" ' +
                        'value="' + (course.id || '') + '" ' + checkboxChecked + '> ' +
                        courseDisplayName.replace(/</g, '&lt;').replace(/>/g, '&gt;') + statusText + '</label>' +
                        '</div>';
                });
                
                console.log('CCS Debug: Generated HTML length:', html.length);
                courseList.html(html);
                if (coursesWrapper.length) {
                    coursesWrapper.show();
                }
                
                // Enable sync button
                $('#ccs-sync-selected').prop('disabled', false);
                
                console.log('CCS Debug: ===== COURSES LOADED SUCCESSFULLY =====');
                console.log('CCS Debug: Displayed ' + coursesData.length + ' courses');
                
                // Show success notice if showNotice function exists
                if (typeof showNotice === 'function') {
                    showNotice('Loaded ' + coursesData.length + ' courses', 'success');
                }
            },
            error: function(xhr, status, error) {
                console.error('CCS Debug: ===== AJAX ERROR =====');
                console.error('CCS Debug: AJAX error:', xhr, status, error);
                console.error('CCS Debug: Response text:', xhr.responseText);
                console.error('CCS Debug: Status code:', xhr.status);
                console.error('CCS Debug: Status text:', xhr.statusText);
                
                let errorMessage = 'Connection error occurred. ';
                if (xhr.responseText) {
                    try {
                        const errorResponse = JSON.parse(xhr.responseText);
                        if (errorResponse.data) {
                            errorMessage += errorResponse.data;
                        }
                    } catch (e) {
                        errorMessage += xhr.responseText;
                    }
                } else {
                    errorMessage += 'Status: ' + xhr.status + ' ' + xhr.statusText;
                }
                
                courseList.html('<p class="error">' + errorMessage + '</p>');
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
                console.log('CCS Debug: AJAX request completed');
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
                    const nameA = $a.data('course-name') || '';
                    const nameB = $b.data('course-name') || '';
                    return nameA.localeCompare(nameB);
                    
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
                    
                    // Secondary sort by date (newest first)
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
