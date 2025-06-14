
import { initCourseManager } from './modules/courses.js';

(function($) {
    'use strict';

    $(document).ready(function() {
        // Initialize course manager
        initCourseManager($);
        
        // Check if required variables are available
        if (typeof window.ccsNonces === 'undefined' || typeof window.ajaxurl === 'undefined') {
            console.error('CCS Admin: Required variables not found');
            return;
        }

        // Test Connection button
        $('#ccs-test-connection').on('click', function(e) {
            e.preventDefault();
            var button = $(this);
            var originalText = button.text();
            
            button.prop('disabled', true).text('Testing...');
            
            $.ajax({
                url: window.ajaxurl,
                type: 'POST',
                data: {
                    action: 'ccs_test_connection',
                    nonce: window.ccsNonces.test_connection
                },
                success: function(response) {
                    if (response.success) {
                        showNotice('Success: ' + response.data, 'success');
                    } else {
                        showNotice('Error: ' + response.data, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Connection test error:', error);
                    showNotice('Error: Failed to test connection', 'error');
                },
                complete: function() {
                    button.prop('disabled', false).text(originalText);
                }
            });
        });

        // Sync Selected Courses button
        $('#ccs-sync-selected').on('click', function(e) {
            e.preventDefault();
            var selectedCourses = [];
            $('.ccs-course-checkbox:checked').each(function() {
                selectedCourses.push($(this).val());
            });
            
            if (selectedCourses.length === 0) {
                showNotice('Please select at least one course to sync.', 'warning');
                return;
            }
            
            var button = $(this);
            var originalText = button.text();
            
            button.prop('disabled', true).text('Syncing...');
            
            $.ajax({
                url: window.ajaxurl,
                type: 'POST',
                data: {
                    action: 'ccs_sync_courses',
                    nonce: window.ccsNonces.sync_courses,
                    course_ids: selectedCourses
                },
                success: function(response) {
                    if (response.success) {
                        displaySyncResults(response.data);
                        showNotice('Sync completed successfully', 'success');
                    } else {
                        showNotice('Error: ' + response.data, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Sync courses error:', error);
                    showNotice('Error: Failed to sync courses', 'error');
                },
                complete: function() {
                    button.prop('disabled', false).text(originalText);
                }
            });
        });

        function displaySyncResults(results) {
            var html = '<div class="sync-results">';
            html += '<h3>Sync Results</h3>';
            html += '<p>' + escapeHtml(results.message || 'Sync completed') + '</p>';
            html += '<ul>';
            html += '<li>Imported: ' + parseInt(results.imported || 0) + '</li>';
            html += '<li>Skipped: ' + parseInt(results.skipped || 0) + '</li>';
            html += '<li>Errors: ' + parseInt(results.errors || 0) + '</li>';
            html += '<li>Total: ' + parseInt(results.total || 0) + '</li>';
            html += '</ul>';
            html += '</div>';
            $('#ccs-sync-status').html(html);
        }
        
        function showNotice(message, type) {
            var noticeClass = 'notice-info';
            if (type === 'success') noticeClass = 'notice-success';
            if (type === 'error') noticeClass = 'notice-error';
            if (type === 'warning') noticeClass = 'notice-warning';
            
            var notice = $('<div class="notice ' + noticeClass + ' is-dismissible"><p>' + escapeHtml(message) + '</p></div>');
            $('.wrap h1').after(notice);
            
            // Auto-dismiss after 5 seconds
            setTimeout(function() {
                notice.fadeOut();
            }, 5000);
        }
        
        function escapeHtml(text) {
            var map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.toString().replace(/[&<>"']/g, function(m) { return map[m]; });
        }
    });

})(jQuery);
