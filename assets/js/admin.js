
/**
 * Canvas Course Sync - Consolidated Admin JavaScript
 * 
 * @package Canvas_Course_Sync
 */

(function($) {
    'use strict';
    
    console.log('CCS: Admin script loaded');
    
    // Verify AJAX object exists
    if (typeof ccsAjax === 'undefined') {
        console.error('CCS: ccsAjax object not available - AJAX calls will fail');
        return;
    }
    
    // Core error handler
    const ErrorHandler = {
        log: function(error, context = '') {
            const timestamp = new Date().toISOString();
            const errorMsg = typeof error === 'string' ? error : (error.message || 'Unknown error');
            console.error(`CCS Error ${timestamp}: [${context}] ${errorMsg}`, error);
            
            // Log to server if available
            if (ccsAjax.nonces && ccsAjax.nonces.logError) {
                $.ajax({
                    url: ccsAjax.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'ccs_log_js_error',
                        nonce: ccsAjax.nonces.logError,
                        message: errorMsg,
                        context: context,
                        url: window.location.href
                    },
                    timeout: 5000
                }).fail(() => console.warn('CCS: Failed to log error to server'));
            }
        },
        
        showUser: function(message, context = '') {
            const fullMsg = context ? `[${context}] ${message}` : message;
            
            // Try to find error container
            let $container = $('#ccs-error-display');
            if (!$container.length) {
                $container = $('.ccs-admin-container').first();
            }
            
            if ($container.length) {
                const $errorDiv = $('<div class="notice notice-error is-dismissible"><p></p></div>');
                $errorDiv.find('p').text(fullMsg);
                $container.prepend($errorDiv);
                setTimeout(() => $errorDiv.fadeOut(), 10000);
            } else {
                alert(fullMsg);
            }
        }
    };
    
    // UI helpers
    const UI = {
        showLoading: function($element, message = 'Loading...') {
            $element.prop('disabled', true).text(message);
        },
        
        hideLoading: function($element, originalText) {
            $element.prop('disabled', false).text(originalText);
        },
        
        showSuccess: function(message) {
            const $container = $('.ccs-admin-container').first();
            const $successDiv = $('<div class="notice notice-success is-dismissible"><p></p></div>');
            $successDiv.find('p').text(message);
            $container.prepend($successDiv);
            setTimeout(() => $successDiv.fadeOut(), 5000);
        }
    };
    
    // Connection testing
    const ConnectionTester = {
        init: function() {
            $(document).on('click', '#ccs-test-connection', function(e) {
                e.preventDefault();
                ConnectionTester.test();
            });
        },
        
        test: function() {
            console.log('CCS: Testing connection');
            
            const $button = $('#ccs-test-connection');
            const $result = $('#ccs-connection-result');
            const originalText = $button.text();
            
            // Validate inputs
            const domain = $('#ccs_canvas_domain').val();
            const token = $('#ccs_canvas_token').val();
            
            if (!domain || !token) {
                ErrorHandler.showUser('Please enter both Canvas domain and API token');
                return;
            }
            
            UI.showLoading($button, 'Testing...');
            $result.html('');
            
            $.ajax({
                url: ccsAjax.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ccs_test_connection',
                    nonce: ccsAjax.nonces.testConnection
                },
                timeout: 30000,
                success: function(response) {
                    if (response.success) {
                        $result.html('<div class="ccs-success">✓ Connection successful!</div>');
                        UI.showSuccess('Canvas API connection verified');
                    } else {
                        const errorMsg = response.data || 'Connection test failed';
                        $result.html(`<div class="ccs-error">✗ ${errorMsg}</div>`);
                        ErrorHandler.log(errorMsg, 'Connection Test');
                    }
                },
                error: function(xhr, status, error) {
                    $result.html('<div class="ccs-error">✗ Connection test failed</div>');
                    ErrorHandler.log(error, 'Connection Test');
                },
                complete: function() {
                    UI.hideLoading($button, originalText);
                }
            });
        }
    };
    
    // Course management
    const CourseManager = {
        syncInProgress: false,
        
        init: function() {
            $(document).on('click', '#ccs-get-courses', CourseManager.getCourses);
            $(document).on('click', '#ccs-sync-selected', CourseManager.syncSelected);
            $(document).on('click', '#ccs-select-all', CourseManager.selectAll);
            $(document).on('click', '#ccs-deselect-all', CourseManager.deselectAll);
            $(document).on('click', '#ccs-omit-selected', CourseManager.omitSelected);
            $(document).on('click', '#ccs-restore-omitted', CourseManager.restoreOmitted);
            $(document).on('click', '#ccs-cleanup-deleted', CourseManager.cleanupDeleted);
        },
        
        getCourses: function(e) {
            e.preventDefault();
            console.log('CCS: Getting courses');
            
            const $button = $(this);
            const $loading = $('#ccs-loading-courses');
            const $wrapper = $('#ccs-courses-wrapper');
            const $list = $('#ccs-course-list');
            
            UI.showLoading($button, 'Loading...');
            $loading.show();
            $wrapper.hide();
            $list.empty();
            
            $.ajax({
                url: ccsAjax.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ccs_get_courses',
                    nonce: ccsAjax.nonces.getCourses
                },
                timeout: 60000,
                success: function(response) {
                    console.log('CCS: Get courses response:', response);
                    
                    if (response.success && response.data) {
                        const courses = response.data.courses || response.data;
                        CourseManager.renderCourses(courses, $list);
                        $wrapper.show();
                    } else {
                        const errorMsg = response.data || 'Failed to load courses';
                        $list.html('<div class="notice notice-error"><p>' + errorMsg + '</p></div>');
                        $wrapper.show();
                    }
                },
                error: function(xhr, status, error) {
                    const errorMsg = 'Network error: ' + error;
                    $list.html('<div class="notice notice-error"><p>' + errorMsg + '</p></div>');
                    $wrapper.show();
                    ErrorHandler.log(error, 'Get Courses');
                },
                complete: function() {
                    UI.hideLoading($button, 'Get Courses');
                    $loading.hide();
                }
            });
        },
        
        renderCourses: function(courses, $container) {
            if (!Array.isArray(courses) || courses.length === 0) {
                $container.html('<div class="notice notice-info"><p>No courses found.</p></div>');
                return;
            }
            
            let html = '<div class="ccs-courses-header">';
            html += '<strong>Found ' + courses.length + ' course(s)</strong>';
            html += '<div class="ccs-course-filters" style="margin-top: 10px;">';
            html += '<label style="margin-right: 15px;"><input type="radio" name="course-filter" value="all" checked> Show All (' + courses.length + ')</label>';
            
            // Count courses by status
            const newCourses = courses.filter(c => c.status === 'available' && !c.is_omitted);
            const syncedCourses = courses.filter(c => c.status === 'synced');
            const omittedCourses = courses.filter(c => c.is_omitted);
            
            html += '<label style="margin-right: 15px;"><input type="radio" name="course-filter" value="new"> New Courses (' + newCourses.length + ')</label>';
            html += '<label style="margin-right: 15px;"><input type="radio" name="course-filter" value="synced"> Already Synced (' + syncedCourses.length + ')</label>';
            html += '<label><input type="radio" name="course-filter" value="omitted"> Omitted (' + omittedCourses.length + ')</label>';
            html += '</div></div>';
            
            html += '<table class="wp-list-table widefat fixed striped">';
            html += '<thead><tr>';
            html += '<th><input type="checkbox" id="ccs-select-all-checkbox"></th>';
            html += '<th>Course Name</th>';
            html += '<th>Canvas ID</th>';
            html += '<th>Status</th>';
            html += '</tr></thead><tbody id="ccs-course-table-body">';
            
            courses.forEach(function(course) {
                const courseId = course.id || 0;
                const courseName = course.name || 'Unnamed Course';
                const isOmitted = course.is_omitted || false;
                
                let statusText = 'Available';
                let statusClass = 'notice-info';
                let rowClass = 'course-available';
                
                if (isOmitted) {
                    statusText = 'Omitted';
                    statusClass = 'notice-warning';
                    rowClass = 'course-omitted';
                } else if (course.status === 'synced') {
                    statusText = 'Synced';
                    statusClass = 'notice-success';
                    rowClass = 'course-synced';
                } else {
                    rowClass = 'course-new';
                }
                
                html += '<tr class="' + rowClass + '" data-status="' + (isOmitted ? 'omitted' : course.status) + '">';
                html += '<td><input type="checkbox" class="ccs-course-checkbox" value="' + courseId + '"></td>';
                html += '<td><strong>' + CourseManager.escapeHtml(courseName) + '</strong></td>';
                html += '<td>' + courseId + '</td>';
                html += '<td><span class="notice ' + statusClass + '">' + statusText + '</span></td>';
                html += '</tr>';
            });
            
            html += '</tbody></table>';
            $container.html(html);
            
            // Bind select all checkbox
            $('#ccs-select-all-checkbox').on('change', function() {
                $('.ccs-course-checkbox:visible').prop('checked', $(this).prop('checked'));
            });
            
            // Bind filter functionality
            $('input[name="course-filter"]').on('change', function() {
                const filterValue = $(this).val();
                const $tableBody = $('#ccs-course-table-body');
                const $rows = $tableBody.find('tr');
                
                if (filterValue === 'all') {
                    $rows.show();
                } else if (filterValue === 'new') {
                    $rows.hide();
                    $rows.filter('.course-new, .course-available').show();
                } else if (filterValue === 'synced') {
                    $rows.hide();
                    $rows.filter('.course-synced').show();
                } else if (filterValue === 'omitted') {
                    $rows.hide();
                    $rows.filter('.course-omitted').show();
                }
                
                // Update select all checkbox to only affect visible courses
                $('#ccs-select-all-checkbox').prop('checked', false);
            });
        },
        
        syncSelected: function(e) {
            e.preventDefault();
            
            if (CourseManager.syncInProgress) {
                alert('Sync already in progress');
                return;
            }
            
            const selectedCourses = $('.ccs-course-checkbox:checked').map(function() {
                return $(this).val();
            }).get();
            
            if (selectedCourses.length === 0) {
                alert('Please select at least one course');
                return;
            }
            
            if (!confirm('Sync ' + selectedCourses.length + ' course(s)?')) {
                return;
            }
            
            CourseManager.syncInProgress = true;
            const $button = $(this);
            const originalText = $button.text();
            
            UI.showLoading($button, 'Syncing...');
            $('#ccs-sync-progress').show();
            $('#ccs-sync-results').hide();
            
            $.ajax({
                url: ccsAjax.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ccs_sync_courses',
                    nonce: ccsAjax.nonces.syncCourses,
                    course_ids: selectedCourses
                },
                timeout: 300000, // 5 minutes
                success: function(response) {
                    console.log('CCS: Sync response:', response);
                    
                    if (response.success && response.data) {
                        const data = response.data;
                        $('#ccs-sync-message').html('<div class="notice notice-success"><p>' + (data.message || 'Sync completed!') + '</p></div>');
                        $('#ccs-imported').text(data.imported || 0);
                        $('#ccs-skipped').text(data.skipped || 0);
                        $('#ccs-errors').text(data.errors || 0);
                        
                        UI.showSuccess('Sync completed successfully');
                        
                        // Refresh page after 3 seconds
                        setTimeout(() => location.reload(), 3000);
                    } else {
                        const errorMsg = response.data || 'Sync failed';
                        $('#ccs-sync-message').html('<div class="notice notice-error"><p>' + errorMsg + '</p></div>');
                        ErrorHandler.log(errorMsg, 'Sync');
                    }
                    
                    $('#ccs-sync-results').show();
                },
                error: function(xhr, status, error) {
                    const errorMsg = 'Sync failed: ' + error;
                    $('#ccs-sync-message').html('<div class="notice notice-error"><p>' + errorMsg + '</p></div>');
                    $('#ccs-sync-results').show();
                    ErrorHandler.log(error, 'Sync');
                },
                complete: function() {
                    CourseManager.syncInProgress = false;
                    UI.hideLoading($button, originalText);
                    $('#ccs-sync-progress').hide();
                }
            });
        },
        
        selectAll: function(e) {
            e.preventDefault();
            $('.ccs-course-checkbox').prop('checked', true);
        },
        
        deselectAll: function(e) {
            e.preventDefault();
            $('.ccs-course-checkbox').prop('checked', false);
        },
        
        omitSelected: function(e) {
            e.preventDefault();
            
            const selectedCourses = $('.ccs-course-checkbox:checked').map(function() {
                return $(this).val();
            }).get();
            
            if (selectedCourses.length === 0) {
                alert('Please select courses to omit');
                return;
            }
            
            if (!confirm('Omit ' + selectedCourses.length + ' course(s) from auto-sync?')) {
                return;
            }
            
            const $button = $(this);
            const originalText = $button.text();
            
            UI.showLoading($button, 'Omitting...');
            
            $.ajax({
                url: ccsAjax.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ccs_omit_courses',
                    nonce: ccsAjax.nonces.omitCourses,
                    course_ids: selectedCourses
                },
                success: function(response) {
                    if (response.success) {
                        UI.showSuccess('Courses omitted successfully');
                        $('#ccs-get-courses').trigger('click'); // Refresh list
                    } else {
                        ErrorHandler.showUser(response.data || 'Failed to omit courses');
                    }
                },
                error: function(xhr, status, error) {
                    ErrorHandler.log(error, 'Omit Courses');
                    ErrorHandler.showUser('Network error while omitting courses');
                },
                complete: function() {
                    UI.hideLoading($button, originalText);
                }
            });
        },
        
        restoreOmitted: function(e) {
            e.preventDefault();
            
            if (!confirm('Restore all omitted courses for auto-sync?')) {
                return;
            }
            
            const $button = $(this);
            const originalText = $button.text();
            
            UI.showLoading($button, 'Restoring...');
            
            $.ajax({
                url: ccsAjax.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ccs_restore_omitted',
                    nonce: ccsAjax.nonces.restoreOmitted
                },
                success: function(response) {
                    if (response.success) {
                        UI.showSuccess('Omitted courses restored successfully');
                        $('#ccs-get-courses').trigger('click'); // Refresh list
                    } else {
                        ErrorHandler.showUser(response.data || 'Failed to restore courses');
                    }
                },
                error: function(xhr, status, error) {
                    ErrorHandler.log(error, 'Restore Omitted');
                    ErrorHandler.showUser('Network error while restoring courses');
                },
                complete: function() {
                    UI.hideLoading($button, originalText);
                }
            });
        },
        
        cleanupDeleted: function(e) {
            e.preventDefault();
            
            if (!confirm('Check for deleted/trashed WordPress courses and update their sync status to "available"?')) {
                return;
            }
            
            const $button = $(this);
            const originalText = $button.text();
            
            UI.showLoading($button, 'Cleaning up...');
            
            $.ajax({
                url: ccsAjax.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ccs_cleanup_deleted',
                    nonce: ccsAjax.nonces.cleanupDeleted
                },
                success: function(response) {
                    if (response.success) {
                        const data = response.data;
                        let message = data.message;
                        
                        if (data.details && data.details.length > 0) {
                            message += '\n\nUpdated courses:';
                            data.details.forEach(function(course) {
                                message += '\n- ' + course.course_title + ' (Canvas ID: ' + course.canvas_id + ')';
                            });
                        }
                        
                        UI.showSuccess(message);
                        $('#ccs-get-courses').trigger('click'); // Refresh course list
                    } else {
                        ErrorHandler.showUser(response.data || 'Failed to cleanup deleted courses');
                    }
                },
                error: function(xhr, status, error) {
                    ErrorHandler.log(error, 'Cleanup Deleted');
                    ErrorHandler.showUser('Network error while cleaning up deleted courses');
                },
                complete: function() {
                    UI.hideLoading($button, originalText);
                }
            });
        },
        
        escapeHtml: function(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, function(m) { return map[m]; });
        }
    };
    
    // Log management
    const LogManager = {
        init: function() {
            $(document).on('click', '#ccs-refresh-logs', LogManager.refresh);
            $(document).on('click', '#ccs-clear-logs', LogManager.clear);
        },
        
        refresh: function(e) {
            e.preventDefault();
            
            const $button = $(this);
            const originalText = $button.text();
            
            UI.showLoading($button, 'Refreshing...');
            
            $.ajax({
                url: ccsAjax.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ccs_refresh_logs',
                    nonce: ccsAjax.nonces.refreshLogs
                },
                success: function(response) {
                    if (response.success && response.data) {
                        $('#ccs-logs-display').html(response.data.html || response.data);
                        UI.showSuccess('Logs refreshed');
                    } else {
                        ErrorHandler.showUser(response.data || 'Failed to refresh logs');
                    }
                },
                error: function(xhr, status, error) {
                    ErrorHandler.log(error, 'Refresh Logs');
                    ErrorHandler.showUser('Failed to refresh logs');
                },
                complete: function() {
                    UI.hideLoading($button, originalText);
                }
            });
        },
        
        clear: function(e) {
            e.preventDefault();
            
            if (!confirm('Clear all logs? This cannot be undone.')) {
                return;
            }
            
            const $button = $(this);
            const originalText = $button.text();
            
            UI.showLoading($button, 'Clearing...');
            
            $.ajax({
                url: ccsAjax.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ccs_clear_logs',
                    nonce: ccsAjax.nonces.clearLogs
                },
                success: function(response) {
                    if (response.success) {
                        $('#ccs-logs-display').html('<div class="notice notice-info"><p>No logs found.</p></div>');
                        UI.showSuccess('Logs cleared');
                    } else {
                        ErrorHandler.showUser(response.data || 'Failed to clear logs');
                    }
                },
                error: function(xhr, status, error) {
                    ErrorHandler.log(error, 'Clear Logs');
                    ErrorHandler.showUser('Failed to clear logs');
                },
                complete: function() {
                    UI.hideLoading($button, originalText);
                }
            });
        }
    };
    
    // Auto-sync management
    const AutoSync = {
        init: function() {
            $(document).on('click', '#ccs-trigger-auto-sync', AutoSync.run);
            $(document).on('change', '#ccs_auto_sync_enabled', AutoSync.toggleEmailRow);
        },
        
        run: function(e) {
            e.preventDefault();
            
            const $button = $(this);
            const originalText = $button.text();
            
            UI.showLoading($button, 'Running...');
            
            $.ajax({
                url: ccsAjax.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ccs_run_auto_sync',
                    nonce: ccsAjax.nonces.runAutoSync
                },
                timeout: 120000, // 2 minutes
                success: function(response) {
                    if (response.success) {
                        const message = response.data.message || 'Auto-sync completed';
                        UI.showSuccess(message);
                    } else {
                        ErrorHandler.showUser(response.data || 'Auto-sync failed');
                    }
                },
                error: function(xhr, status, error) {
                    ErrorHandler.log(error, 'Auto Sync');
                    ErrorHandler.showUser('Auto-sync failed');
                },
                complete: function() {
                    UI.hideLoading($button, originalText);
                }
            });
        },
        
        toggleEmailRow: function() {
            const isChecked = $(this).is(':checked');
            const emailRow = $('#ccs-email-row');
            const emailInput = $('#ccs_notification_email');
            
            if (isChecked) {
                emailRow.show();
                emailInput.prop('required', true);
            } else {
                emailRow.hide();
                emailInput.prop('required', false);
            }
        }
    };
    
    // Debug panel functionality
    const DebugPanel = {
        init: function() {
            console.log('CCS Debug: Admin page DOM ready');
            
            // Update status indicators
            $('#js-status').text('Loaded');
            
            if (typeof ccsAjax !== 'undefined') {
                $('#ajax-status').html('<span class="ccs-debug-status-available">Available</span>');
                console.log('CCS Debug: ccsAjax object:', ccsAjax);
            } else {
                $('#ajax-status').html('<span class="ccs-debug-status-missing">Missing</span>');
                console.error('CCS Debug: ccsAjax object not available');
            }
        }
    };
    
    // GitHub update checker - make it globally available
    window.ccsCheckForUpdates = function() {
        console.log('CCS: Checking for updates');
        
        if (!ccsAjax.nonces.checkUpdates) {
            console.error('CCS: Update check nonce not available');
            alert('Update check not available - missing security nonce');
            return;
        }
        
        $.ajax({
            url: ccsAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ccs_check_updates',
                nonce: ccsAjax.nonces.checkUpdates
            },
            timeout: 30000,
            success: function(response) {
                if (response.success) {
                    alert(response.data.message || 'Update check completed');
                } else {
                    alert('Update check failed: ' + (response.data || 'Unknown error'));
                }
            },
            error: function(xhr, status, error) {
                console.error('CCS: Update check failed:', error);
                alert('Update check failed: ' + error);
            }
        });
    };
    // Initialize everything when document is ready
    $(document).ready(function() {
        console.log('CCS: Initializing admin functionality');
        
        try {
            ConnectionTester.init();
            CourseManager.init();
            LogManager.init();
            AutoSync.init();
            DebugPanel.init();
            
            console.log('CCS: All modules initialized successfully');
        } catch (error) {
            ErrorHandler.log(error, 'Initialization');
        }
    });
    
})(jQuery);
