/**
 * Course management functionality
 */

// Export the initialization function for use by admin.js
if (typeof window !== 'undefined') {
    window.initCourseManager = initCourseManager;
}

function initCourseManager($) {
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
                
                // Build controls section HTML with proper styling
                let html = '<div class="ccs-controls-section" style="background: #f9f9f9; padding: 15px; margin-bottom: 15px; border: 1px solid #ddd; border-radius: 4px;">' +
                    '<div class="ccs-select-all" style="margin-bottom: 10px;">' +
                    '<label style="font-weight: bold;">' +
                    '<input type="checkbox" id="ccs-select-all-checkbox" checked style="margin-right: 5px;"> ' +
                    'Select/Deselect All</label>' +
                    '</div>' +
                    '<div class="ccs-filter-sort-row" style="display: flex; gap: 20px; align-items: center; flex-wrap: wrap;">' +
                    '<div class="ccs-filter-controls">' +
                    '<label for="ccs-status-filter" style="margin-right: 5px; font-weight: bold;">Filter by Status: </label>' +
                    '<select id="ccs-status-filter" class="ccs-filter-dropdown" style="padding: 5px;">' +
                    '<option value="all">All Courses</option>' +
                    '<option value="new">New Only</option>' +
                    '<option value="exists">Title Exists Only</option>' +
                    '<option value="synced">Already Synced Only</option>' +
                    '</select>' +
                    '</div>' +
                    '<div class="ccs-sort-controls">' +
                    '<label for="ccs-sort-select" style="margin-right: 5px; font-weight: bold;">Sort by: </label>' +
                    '<select id="ccs-sort-select" class="ccs-sort-dropdown" style="padding: 5px;">' +
                    '<option value="status">Status (New → Existing → Synced)</option>' +
                    '<option value="name">Course Name (A-Z)</option>' +
                    '<option value="date">Creation Date (Newest First)</option>' +
                    '</select>' +
                    '</div>' +
                    '</div>' +
                    '</div>';
                    
                // Build course items HTML
                sortedCourses.forEach(function(course) {
                    let statusClass = '';
                    let statusText = '';
                    let checkboxChecked = 'checked';
                    
                    console.log('CCS Debug: Processing course:', course.name, 'status:', course.status);
                    
                    // Use the explicit status from backend with proper labeling
                    if (course.status === 'synced') {
                        statusClass = 'ccs-course-exists';
                        statusText = ' <span class="ccs-status-badge ccs-exists-canvas-id" style="background: #dc3545; color: white; padding: 3px 8px; border-radius: 3px; font-size: 12px; margin-left: 10px;">' + 
                                   (course.status_label || 'Already synced') + '</span>';
                        checkboxChecked = ''; // Don't pre-select already synced courses
                    } else if (course.status === 'exists') {
                        statusClass = 'ccs-course-exists';
                        statusText = ' <span class="ccs-status-badge ccs-exists-title" style="background: #ffc107; color: #212529; padding: 3px 8px; border-radius: 3px; font-size: 12px; margin-left: 10px;">' + 
                                   (course.status_label || 'Title exists in WP') + '</span>';
                        checkboxChecked = ''; // Don't pre-select existing title courses
                    } else {
                        // New course
                        statusText = ' <span class="ccs-status-badge ccs-new-course" style="background: #28a745; color: white; padding: 3px 8px; border-radius: 3px; font-size: 12px; margin-left: 10px;">' + 
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
                        'data-status="' + (course.status || 'new') + '" ' +
                        'style="margin: 8px 0; padding: 12px; border: 1px solid #ddd; border-radius: 4px; background: white;">' +
                        '<label style="display: flex; align-items: center; cursor: pointer;">' +
                        '<input type="checkbox" class="ccs-course-checkbox course-checkbox" ' +
                        'value="' + (course.id || '') + '" ' + checkboxChecked + ' style="margin-right: 10px;"> ' +
                        '<span style="flex: 1;">' + courseDisplayName.replace(/</g, '&lt;').replace(/>/g, '&gt;') + statusText + '</span>' +
                        '</label>' +
                        '</div>';
                });
                
                console.log('CCS Debug: Generated HTML length:', html.length);
                courseList.html(html);
                if (coursesWrapper.length) {
                    coursesWrapper.show();
                }
                
                // Make sure both buttons are visible and enabled
                console.log('CCS Debug: Enabling sync and omit buttons');
                $('#ccs-sync-selected').show().prop('disabled', false);
                $('#ccs-omit-courses').show().prop('disabled', false);
                
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

    // Add sync selected courses functionality
    $(document).on('click', '#ccs-sync-selected', function(e) {
        e.preventDefault();
        console.log('CCS Debug: Sync selected courses button clicked');
        
        const selectedCourses = $('.ccs-course-checkbox:checked').map(function() {
            return $(this).val();
        }).get();
        
        console.log('CCS Debug: Selected courses for sync:', selectedCourses);
        
        if (selectedCourses.length === 0) {
            alert('Please select at least one course to sync.');
            return;
        }
        
        const confirmMsg = 'Are you sure you want to sync ' + selectedCourses.length + ' selected course(s)?';
        if (!confirm(confirmMsg)) {
            return;
        }
        
        const button = $(this);
        const originalText = button.text();
        const progress = $('#ccs-sync-progress');
        const results = $('#ccs-sync-results');
        
        button.prop('disabled', true).text('Syncing...');
        progress.show();
        results.hide();
        
        $.ajax({
            url: ccsAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ccs_sync_courses',
                nonce: ccsAjax.syncCoursesNonce,
                course_ids: selectedCourses
            },
            success: function(response) {
                console.log('CCS Debug: Sync response:', response);
                button.prop('disabled', false).text(originalText);
                progress.hide();
                
                if (response.success && response.data) {
                    const data = response.data;
                    $('#ccs-sync-message').html('<div class="notice notice-success inline"><p>' + (data.message || 'Sync completed successfully!') + '</p></div>');
                    $('#ccs-imported').text(data.imported || 0);
                    $('#ccs-skipped').text(data.skipped || 0);
                    $('#ccs-errors').text(data.errors || 0);
                } else {
                    const errorMessage = response.data && response.data.message ? response.data.message : 'Sync failed with unknown error.';
                    $('#ccs-sync-message').html('<div class="notice notice-error inline"><p>' + errorMessage + '</p></div>');
                    console.error('CCS Debug: Sync failed:', response);
                }
                
                results.show();
            },
            error: function(xhr, status, error) {
                console.error('CCS Debug: Sync AJAX error:', error, xhr.responseText);
                button.prop('disabled', false).text(originalText);
                progress.hide();
                
                let errorDetails = '';
                try {
                    const errorResponse = JSON.parse(xhr.responseText);
                    errorDetails = errorResponse.data && errorResponse.data.message ? errorResponse.data.message : xhr.responseText;
                } catch (e) {
                    errorDetails = xhr.responseText || error;
                }
                
                $('#ccs-sync-message').html('<div class="notice notice-error inline"><p><strong>Connection Error:</strong> ' + error + '<br><small>Details: ' + errorDetails + '</small></p></div>');
                results.show();
            }
        });
    });

    // Handle select all checkbox
    $(document).on('change', '#ccs-select-all-checkbox', function() {
        const isChecked = $(this).prop('checked');
        $('.ccs-course-checkbox:visible').prop('checked', isChecked);
        console.log('CCS Debug: Select all checkbox changed to:', isChecked);
    });
    
    // Handle status filter dropdown
    $(document).on('change', '#ccs-status-filter', function() {
        const filterValue = $(this).val();
        const courseItems = $('.ccs-course-item');
        
        console.log('CCS Debug: Filtering courses by status:', filterValue);
        
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
        
        console.log('CCS Debug: Sorting courses by:', sortBy);
        
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
    
    // Handle omit courses button
    $(document).on('click', '#ccs-omit-courses', function(e) {
        e.preventDefault();
        console.log('CCS Debug: Omit courses button clicked');
        
        const selectedCourses = $('.ccs-course-checkbox:checked').map(function() {
            return {
                id: $(this).val(),
                name: $(this).closest('.ccs-course-item').find('span').text().split(' (')[0] // Get course name without code
            };
        }).get();
        
        console.log('CCS Debug: Selected courses to omit:', selectedCourses);
        
        if (selectedCourses.length === 0) {
            alert('Please select at least one course to omit from future syncing.');
            return;
        }
        
        const confirmMsg = 'Are you sure you want to omit ' + selectedCourses.length + ' selected course(s) from future syncing? This will add them to the exclusion list.';
        if (!confirm(confirmMsg)) {
            return;
        }
        
        const button = $(this);
        const originalText = button.text();
        button.prop('disabled', true).text('Omitting...');
        
        $.ajax({
            url: ccsAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ccs_omit_courses',
                nonce: ccsAjax.omitCoursesNonce || ccsAjax.getCoursesNonce, // Fallback to existing nonce
                courses: selectedCourses
            },
            success: function(response) {
                button.prop('disabled', false).text(originalText);
                
                if (response.success) {
                    alert('Successfully omitted ' + selectedCourses.length + ' course(s) from future syncing.');
                    
                    // Update the UI to show omitted courses
                    selectedCourses.forEach(function(course) {
                        const courseItem = $('.ccs-course-checkbox[value="' + course.id + '"]').closest('.ccs-course-item');
                        const statusBadge = courseItem.find('.ccs-status-badge');
                        
                        // Update status to omitted
                        statusBadge.removeClass('ccs-new-course ccs-exists-title ccs-exists-canvas-id')
                                  .addClass('ccs-omitted-course')
                                  .css({
                                      'background': '#6c757d',
                                      'color': 'white'
                                  })
                                  .text('Omitted');
                        
                        // Update data attributes
                        courseItem.attr('data-status', 'omitted');
                        
                        // Uncheck the checkbox
                        $('.ccs-course-checkbox[value="' + course.id + '"]').prop('checked', false);
                    });
                    
                    // Update select all checkbox
                    updateSelectAllCheckbox();
                } else {
                    const errorMsg = response.data && response.data.message ? response.data.message : 'Failed to omit courses.';
                    alert('Error: ' + errorMsg);
                }
            },
            error: function(xhr, status, error) {
                button.prop('disabled', false).text(originalText);
                alert('Failed to omit courses. Please try again. Error: ' + error);
            }
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
