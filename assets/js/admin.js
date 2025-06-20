
// Use IIFE pattern instead of ES6 modules for better WordPress compatibility
(function($) {
    'use strict';
    
    console.log('CCS Debug: Admin JS loaded');
    console.log('CCS Debug: ccsAjax available:', typeof ccsAjax !== 'undefined');
    console.log('CCS Debug: jQuery available:', typeof $ !== 'undefined');
    
    if (typeof ccsAjax === 'undefined') {
        console.error('CCS Debug: ccsAjax object not available - AJAX calls will fail');
        return;
    }
    
    console.log('CCS Debug: ccsAjax object:', ccsAjax);
    
    // Global nonces object for course module
    window.ccsNonces = {
        get_courses: ccsAjax.getCoursesNonce,
        sync_courses: ccsAjax.syncCoursesNonce,
        sync_status: ccsAjax.syncStatusNonce
    };
    
    // Connection tester functionality
    function initConnectionTester() {
        console.log('CCS Debug: Initializing connection tester');
        
        const testButton = $('#ccs-test-connection');
        console.log('CCS Debug: Test connection button found:', testButton.length);
        
        if (testButton.length === 0) {
            console.warn('CCS Debug: Test connection button not found in DOM');
            return;
        }
        
        testButton.off('click').on('click', function(e) {
            console.log('CCS Debug: Test connection button clicked');
            e.preventDefault();
            
            const button = $(this);
            const resultContainer = $('#ccs-connection-result');
            
            console.log('CCS Debug: Starting connection test AJAX request');
            
            button.attr('disabled', true);
            resultContainer.html('<div class="ccs-spinner"></div> Testing connection...');
            
            $.ajax({
                url: ccsAjax.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ccs_test_connection',
                    nonce: ccsAjax.testConnectionNonce
                },
                success: function(response) {
                    button.attr('disabled', false);
                    
                    console.log('CCS Debug: Connection test response:', response);
                    
                    if (response.success) {
                        resultContainer.html('<div class="ccs-success" style="color: green; padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px;">' + response.data + '</div>');
                    } else {
                        const errorMsg = response.data || 'Unknown error occurred';
                        resultContainer.html('<div class="ccs-error" style="color: #721c24; padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px;">Connection failed: ' + errorMsg + '</div>');
                        console.error('CCS Debug: Connection test failed:', errorMsg);
                    }
                },
                error: function(xhr, status, error) {
                    button.attr('disabled', false);
                    console.error('CCS Debug: Connection test AJAX error:', {
                        status: status, 
                        error: error, 
                        responseText: xhr.responseText,
                        xhr: xhr
                    });
                    
                    let errorMessage = 'Connection error: ' + error;
                    if (xhr.responseText) {
                        try {
                            const parsed = JSON.parse(xhr.responseText);
                            if (parsed.data) {
                                errorMessage = 'Connection error: ' + parsed.data;
                            }
                        } catch (e) {
                            // Use default error message
                        }
                    }
                    
                    resultContainer.html('<div class="ccs-error" style="color: #721c24; padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px;">' + errorMessage + '</div>');
                },
                timeout: 30000
            });
        });
        
        console.log('CCS Debug: Connection tester event handler attached');
    }
    
    // Log manager functionality
    function initLogManager() {
        console.log('CCS Debug: Initializing log manager');
        
        // Clear logs functionality
        $('#ccs-clear-logs').off('click').on('click', function(e) {
            e.preventDefault();
            console.log('CCS Debug: Clear logs button clicked');
            
            const button = $(this);
            const originalText = button.text();
            button.attr('disabled', true).text('Clearing...');
            
            $.ajax({
                url: ccsAjax.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ccs_clear_logs',
                    nonce: ccsAjax.clearLogsNonce
                },
                success: function(response) {
                    console.log('CCS Debug: Clear logs response:', response);
                    button.attr('disabled', false).text(originalText);
                    if (response.success) {
                        $('#ccs-logs-display').html('<div class="notice notice-success"><p>Logs cleared successfully.</p></div>');
                        setTimeout(function() {
                            refreshLogs();
                        }, 1000);
                    } else {
                        const errorMsg = response.data && response.data.message ? response.data.message : (response.data || 'Unknown error');
                        alert('Failed to clear logs: ' + errorMsg);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('CCS Debug: Clear logs error:', error, xhr.responseText);
                    button.attr('disabled', false).text(originalText);
                    alert('Failed to clear logs. Please try again. Error: ' + error);
                }
            });
        });
        
        // Refresh logs functionality
        $('#ccs-refresh-logs').off('click').on('click', function(e) {
            e.preventDefault();
            console.log('CCS Debug: Refresh logs button clicked');
            refreshLogs();
        });
        
        function refreshLogs() {
            const button = $('#ccs-refresh-logs');
            const originalText = button.text();
            button.attr('disabled', true).text('Refreshing...');
            
            $.ajax({
                url: ccsAjax.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ccs_refresh_logs',
                    nonce: ccsAjax.refreshLogsNonce
                },
                success: function(response) {
                    console.log('CCS Debug: Refresh logs response:', response);
                    button.attr('disabled', false).text(originalText);
                    if (response.success) {
                        if (response.data && response.data.html) {
                            $('#ccs-logs-display').html(response.data.html);
                        } else {
                            $('#ccs-logs-display').html('<div class="notice notice-info"><p>No logs data received.</p></div>');
                        }
                    } else {
                        const errorMsg = response.data && response.data.message ? response.data.message : (response.data || 'Unknown error');
                        alert('Failed to refresh logs: ' + errorMsg);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('CCS Debug: Refresh logs error:', error, xhr.responseText);
                    button.attr('disabled', false).text(originalText);
                    alert('Failed to refresh logs. Please try again. Error: ' + error);
                }
            });
        }
    }
    
    // Initialize all functionality when document is ready
    $(document).ready(function() {
        console.log('CCS Debug: Document ready, initializing admin modules');
        
        try {
            initConnectionTester();
            console.log('CCS Debug: Connection tester initialized');
        } catch (error) {
            console.error('CCS Debug: Failed to initialize connection tester:', error);
        }
        
        try {
            initLogManager();
            console.log('CCS Debug: Log manager initialized');
        } catch (error) {
            console.error('CCS Debug: Failed to initialize log manager:', error);
        }
        
        // Initialize course manager if the module is loaded
        if (typeof initCourseManager === 'function') {
            try {
                initCourseManager($);
                console.log('CCS Debug: Course manager initialized');
            } catch (error) {
                console.error('CCS Debug: Failed to initialize course manager:', error);
            }
        }
        
        console.log('CCS Debug: All admin modules initialization completed');
    });
    
})(jQuery);
