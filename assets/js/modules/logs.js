
/**
 * Log management functionality
 */
export function initLogManager($) {
    console.log('CCS Debug: Initializing log manager');
    
    // Clear logs functionality
    $('#ccs-clear-logs').on('click', function() {
        console.log('CCS Debug: Clear logs button clicked');
        const button = $(this);
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
                button.attr('disabled', false).text('Clear All Logs');
                if (response.success) {
                    $('#ccs-logs-display').html('<div class="notice notice-success"><p>Logs cleared successfully.</p></div>');
                    // Auto-refresh logs after clearing
                    refreshLogs();
                } else {
                    alert('Failed to clear logs: ' + (response.data || 'Unknown error'));
                }
            },
            error: function(xhr, status, error) {
                console.error('CCS Debug: Clear logs error:', error, xhr.responseText);
                button.attr('disabled', false).text('Clear All Logs');
                alert('Failed to clear logs. Please try again. Error: ' + error);
            }
        });
    });
    
    // Refresh logs functionality
    $('#ccs-refresh-logs').on('click', function() {
        console.log('CCS Debug: Refresh logs button clicked');
        refreshLogs();
    });
    
    // Function to refresh logs
    function refreshLogs() {
        const button = $('#ccs-refresh-logs');
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
                button.attr('disabled', false).text('Refresh Logs');
                if (response.success) {
                    $('#ccs-logs-display').html(response.data.html);
                } else {
                    alert('Failed to refresh logs: ' + (response.data || 'Unknown error'));
                }
            },
            error: function(xhr, status, error) {
                console.error('CCS Debug: Refresh logs error:', error, xhr.responseText);
                button.attr('disabled', false).text('Refresh Logs');
                alert('Failed to refresh logs. Please try again. Error: ' + error);
            }
        });
    }
}
