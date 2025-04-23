
/**
 * Log management functionality
 */
export function initLogManager($) {
    $('#ccs-clear-logs').on('click', function() {
        const button = $(this);
        button.attr('disabled', true);
        console.log('Clear logs button clicked');
        console.log('Using nonce:', ccsData.clearLogsNonce);
        
        $.ajax({
            url: ccsData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ccs_clear_logs',
                nonce: ccsData.clearLogsNonce
            },
            success: function(response) {
                console.log('Clear logs response:', response);
                button.attr('disabled', false);
                if (response.success) {
                    $('.ccs-log-container').html('<p>Logs cleared successfully.</p>');
                } else {
                    alert('Failed to clear logs: ' + (response.data || 'Unknown error'));
                }
            },
            error: function(xhr, status, error) {
                console.error('Clear logs AJAX error:', error);
                button.attr('disabled', false);
                alert('Failed to clear logs. Please try again. Error: ' + error);
            }
        });
    });
}
