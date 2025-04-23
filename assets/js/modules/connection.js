
/**
 * Connection testing functionality
 */
export function initConnectionTester($) {
    $('#ccs-test-connection').on('click', function() {
        const button = $(this);
        const statusSpan = $('#ccs-connection-status');
        
        button.attr('disabled', true);
        statusSpan.html('Testing connection...').removeClass('ccs-status-success ccs-status-error');
        
        $.ajax({
            url: ccsData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ccs_test_connection',
                nonce: ccsData.testConnectionNonce
            },
            success: function(response) {
                button.attr('disabled', false);
                if (response.success) {
                    statusSpan.html('✓ ' + response.data).addClass('ccs-status-success');
                } else {
                    statusSpan.html('✗ ' + response.data).addClass('ccs-status-error');
                }
            },
            error: function() {
                button.attr('disabled', false);
                statusSpan.html('✗ Connection error').addClass('ccs-status-error');
            }
        });
    });
}
