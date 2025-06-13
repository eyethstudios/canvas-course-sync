
/**
 * Auto-sync functionality
 */
export function initAutoSync($) {
    $('#ccs-trigger-auto-sync').on('click', function() {
        const button = $(this);
        const resultContainer = $('#ccs-auto-sync-result');
        
        button.attr('disabled', true);
        resultContainer.html('<div class="ccs-spinner"></div> Running auto-sync...');
        
        $.ajax({
            url: ccsData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ccs_run_auto_sync',
                nonce: ccsData.autoSyncNonce
            },
            success: function(response) {
                button.attr('disabled', false);
                
                if (response.success) {
                    resultContainer.html('<div class="ccs-success">' + response.data.message + '</div>');
                } else {
                    const errorMsg = response.data || 'Auto-sync failed';
                    resultContainer.html('<div class="ccs-error">' + errorMsg + '</div>');
                }
            },
            error: function(xhr, status, error) {
                button.attr('disabled', false);
                resultContainer.html('<div class="ccs-error">Error: ' + error + '</div>');
            },
            timeout: 60000 // 60 second timeout for auto-sync
        });
    });
}
