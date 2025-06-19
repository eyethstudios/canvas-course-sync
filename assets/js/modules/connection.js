
/**
 * Connection testing functionality
 */
export function initConnectionTester($) {
    console.log('CCS Debug: Initializing connection tester');
    
    // Test if button exists before attaching handler
    const testButton = $('#ccs-test-connection');
    console.log('CCS Debug: Test connection button found:', testButton.length);
    
    if (testButton.length === 0) {
        console.warn('CCS Debug: Test connection button not found in DOM');
        return;
    }
    
    testButton.on('click', function(e) {
        console.log('CCS Debug: Test connection button clicked');
        e.preventDefault();
        
        const button = $(this);
        const resultContainer = $('#ccs-connection-result');
        
        // Check if ccsAjax is available
        if (typeof ccsAjax === 'undefined') {
            console.error('CCS Debug: ccsAjax not available during button click');
            resultContainer.html('<div class="ccs-error">JavaScript configuration error. Please refresh the page.</div>');
            return;
        }
        
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
                    resultContainer.html('<div class="ccs-success">' + response.data + '</div>');
                } else {
                    const errorMsg = response.data || 'Unknown error occurred';
                    resultContainer.html('<div class="ccs-error">Connection failed: ' + errorMsg + '</div>');
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
                
                resultContainer.html('<div class="ccs-error">' + errorMessage + '</div>');
            },
            timeout: 30000 // 30 second timeout
        });
    });
    
    console.log('CCS Debug: Connection tester event handler attached');
}
