
/**
 * Canvas Course Sync Admin JavaScript
 */
(function($) {
    'use strict';
    
    $(document).ready(function() {
        // Test connection button
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
        
        // Sync courses button
        $('#ccs-sync-courses').on('click', function() {
            const button = $(this);
            const progress = $('#ccs-sync-progress');
            const results = $('#ccs-sync-results');
            
            button.attr('disabled', true);
            progress.show();
            results.hide();
            
            $.ajax({
                url: ccsData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ccs_sync_courses',
                    nonce: ccsData.syncNonce
                },
                success: function(response) {
                    button.attr('disabled', false);
                    progress.hide();
                    
                    if (response.success) {
                        $('#ccs-sync-message').html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');
                        $('#ccs-imported').text(response.data.imported);
                        $('#ccs-skipped').text(response.data.skipped);
                        $('#ccs-errors').text(response.data.errors);
                    } else {
                        $('#ccs-sync-message').html('<div class="notice notice-error inline"><p>' + response.data + '</p></div>');
                    }
                    
                    results.show();
                    
                    // Refresh the page to update log display
                    setTimeout(function() {
                        location.reload();
                    }, 5000);
                },
                error: function() {
                    button.attr('disabled', false);
                    progress.hide();
                    $('#ccs-sync-message').html('<div class="notice notice-error inline"><p>Connection error occurred. Please try again.</p></div>');
                    results.show();
                }
            });
        });
    });
})(jQuery);
