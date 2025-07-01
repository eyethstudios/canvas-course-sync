/**
 * Canvas Course Sync - Connection Management Module
 * Handles Canvas API connection testing and validation
 * 
 * @package Canvas_Course_Sync
 */

(function($) {
    'use strict';

    // Connection Module
    window.CCSConnection = {
        
        /**
         * Test Canvas API connection
         */
        testConnection: function() {
            console.log('CCS Connection: Testing Canvas API connection');
            
            const $button = $('#ccs-test-connection');
            const $resultDiv = $('#ccs-connection-result');
            const originalText = $button.text();
            
            // Validate inputs first
            if (!this.validateInputs()) {
                return;
            }
            
            // Show loading state
            CCSAdmin.ui.showLoading($button, 'Testing...');
            $resultDiv.html('');
            
            // Make AJAX request
            CCSAdmin.ajax.request({
                data: {
                    action: 'ccs_test_connection',
                    nonce: window.ccsAjax.testConnectionNonce || ''
                },
                context: 'Connection Test'
            }).done(function(response) {
                console.log('CCS Connection: Test response:', response);
                
                if (response.success) {
                    $resultDiv.html('<div class="ccs-success">✓ Connection successful!</div>');
                    CCSAdmin.ui.showSuccess('Canvas API connection verified');
                } else {
                    const errorMsg = response.data || 'Connection test failed';
                    $resultDiv.html(`<div class="ccs-error">✗ ${errorMsg}</div>`);
                    CCSAdmin.errorHandler.handleError(errorMsg, 'Connection Test');
                }
            }).fail(function() {
                $resultDiv.html('<div class="ccs-error">✗ Connection test failed</div>');
            }).always(function() {
                CCSAdmin.ui.hideLoading($button, originalText);
            });
        },

        /**
         * Validate connection inputs
         */
        validateInputs: function() {
            const domain = $('#ccs_canvas_domain').val();
            const token = $('#ccs_canvas_token').val();
            
            if (!domain || !token) {
                CCSAdmin.errorHandler.showUserError(
                    'Please enter both Canvas domain and API token before testing connection',
                    'Validation'
                );
                return false;
            }
            
            // Basic URL validation
            try {
                new URL(domain);
            } catch (e) {
                CCSAdmin.errorHandler.showUserError(
                    'Please enter a valid Canvas domain URL (e.g., https://canvas.instructure.com)',
                    'Validation'
                );
                return false;
            }
            
            return true;
        },

        /**
         * Initialize connection module
         */
        init: function() {
            console.log('CCS Connection: Initializing connection module');
            
            // Bind test connection button
            $(document).on('click', '#ccs-test-connection', () => {
                this.testConnection();
            });
            
            // Auto-test when domain/token changes (with debounce)
            let testTimeout;
            $('#ccs_canvas_domain, #ccs_canvas_token').on('input', function() {
                clearTimeout(testTimeout);
                testTimeout = setTimeout(() => {
                    const domain = $('#ccs_canvas_domain').val();
                    const token = $('#ccs_canvas_token').val();
                    if (domain && token) {
                        $('#ccs-connection-result').html('<div style="color: #666;">Settings changed - click "Test Connection" to verify</div>');
                    }
                }, 1000);
            });
            
            console.log('CCS Connection: Module initialized');
        }
    };

    // Initialize when core is ready
    $(document).ready(function() {
        if (window.CCSAdmin) {
            CCSConnection.init();
        } else {
            // Wait for core to load
            setTimeout(() => CCSConnection.init(), 100);
        }
    });

})(jQuery);