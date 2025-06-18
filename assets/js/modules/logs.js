
/**
 * Log management functionality
 */
export function initLogManager($) {
    $('#ccs-clear-logs').on('click', function() {
        const button = $(this);
        button.attr('disabled', true);
        
        $.ajax({
            url: ccsAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ccs_clear_logs',
                nonce: ccsAjax.clearLogsNonce
            },
            success: function(response) {
                button.attr('disabled', false);
                if (response.success) {
                    $('.ccs-log-container').html('<p>Logs cleared successfully.</p>');
                } else {
                    alert('Failed to clear logs: ' + (response.data || 'Unknown error'));
                }
            },
            error: function(xhr, status, error) {
                button.attr('disabled', false);
                alert('Failed to clear logs. Please try again. Error: ' + error);
            }
        });
    });
}
