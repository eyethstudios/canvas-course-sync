
/**
 * Log management functionality
 */
export function initLogManager($) {
    console.log('CCS Debug: Initializing log manager');
    
    // Check if we're on the logs page
    if (!$('#ccs-clear-logs').length && !$('#ccs-refresh-logs').length) {
        console.log('CCS Debug: Not on logs page, skipping log manager init');
        return;
    }
    
    console.log('CCS Debug: Found logs buttons, setting up handlers');
    console.log('CCS Debug: Clear logs button count:', $('#ccs-clear-logs').length);
    console.log('CCS Debug: Refresh logs button count:', $('#ccs-refresh-logs').length);
    
    // Clear logs functionality
    $('#ccs-clear-logs').off('click').on('click', function(e) {
        e.preventDefault();
        console.log('CCS Debug: Clear logs button clicked');
        
        if (!ccsAjax || !ccsAjax.clearLogsNonce) {
            console.error('CCS Debug: ccsAjax or clearLogsNonce not available');
            alert('Error: AJAX configuration not available. Please refresh the page.');
            return;
        }
        
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
                    // Auto-refresh logs after clearing
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
    
    // Function to refresh logs
    function refreshLogs() {
        if (!ccsAjax || !ccsAjax.refreshLogsNonce) {
            console.error('CCS Debug: ccsAjax or refreshLogsNonce not available');
            alert('Error: AJAX configuration not available. Please refresh the page.');
            return;
        }
        
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
    
    console.log('CCS Debug: Log manager initialized successfully');
}
