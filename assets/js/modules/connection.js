
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
            url: ccsAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ccs_test_connection',
                nonce: ccsAjax.testConnectionNonce
            },
            success: function(response) {
                button.attr('disabled', false);
                
                console.log('Connection test response:', response);
                
                if (response.success) {
                    resultContainer.html('<div class="ccs-success">' + response.data + '</div>');
                } else {
                    const errorMsg = response.data || 'Unknown error occurred';
                    resultContainer.html('<div class="ccs-error">Connection failed: ' + errorMsg + '</div>');
                    console.error('Connection test failed:', errorMsg);
                }
            },
            error: function(xhr, status, error) {
                button.attr('disabled', false);
                console.error('Connection test AJAX error:', {status, error, responseText: xhr.responseText});
                resultContainer.html('<div class="ccs-error">Connection error: ' + error + '</div>');
            },
            timeout: 30000 // 30 second timeout
        });
    });
}
