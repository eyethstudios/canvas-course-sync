
// Simple button test script
console.log('CCS Test: Button test script loaded');

jQuery(document).ready(function($) {
    console.log('CCS Test: Document ready, testing button functionality');
    
    // Test if buttons exist
    console.log('CCS Test: Test connection button exists:', $('#ccs-test-connection').length > 0);
    console.log('CCS Test: Get courses button exists:', $('#ccs-get-courses').length > 0);
    
    // Test if ccsAjax is available
    console.log('CCS Test: ccsAjax available:', typeof ccsAjax !== 'undefined');
    if (typeof ccsAjax !== 'undefined') {
        console.log('CCS Test: AJAX URL:', ccsAjax.ajaxUrl);
        console.log('CCS Test: Test nonce:', ccsAjax.testConnectionNonce ? 'Available' : 'Missing');
    }
    
    // Add click test
    $('#ccs-test-connection').on('click', function() {
        console.log('CCS Test: Test connection button clicked!');
        alert('Button click detected! Check console for AJAX details.');
    });
    
    $('#ccs-get-courses').on('click', function() {
        console.log('CCS Test: Get courses button clicked!');
        alert('Get courses button click detected!');
    });
});
