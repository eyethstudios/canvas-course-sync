
/**
 * Connection testing functionality
 */
export function initConnectionTester($) {
    $('#ccs-test-connection').on('click', function() {
        const button = $(this);
        const resultContainer = $('#ccs-connection-result');
        
        button.attr('disabled', true);
        resultContainer.html('<div class="ccs-spinner"></div> Testing connection...');
        
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
                    resultContainer.html('<div class="ccs-success">' + response.data + '</div>');
                } else {
                    resultContainer.html('<div class="ccs-error">Connection failed: ' + (response.data || 'Unknown error') + '</div>');
                }
            },
            error: function(xhr, status, error) {
                button.attr('disabled', false);
                resultContainer.html('<div class="ccs-error">Connection error: ' + error + '</div>');
            }
        });
    });
}
