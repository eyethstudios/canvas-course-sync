/**
 * Canvas Course Sync - Core Admin JavaScript Module
 * Centralized error handling and base functionality
 * 
 * @package Canvas_Course_Sync
 */

(function($) {
    'use strict';

    // Core CCS Admin Object
    window.CCSAdmin = {
        
        /**
         * Configuration and state
         */
        config: {
            ajaxUrl: window.ccsAjax?.ajaxUrl || '',
            debug: true,
            retryAttempts: 3,
            retryDelay: 1000
        },

        /**
         * Centralized error handler
         */
        errorHandler: {
            
            /**
             * Log error to console and display to user
             */
            handleError: function(error, context = '', showUser = true) {
                const timestamp = new Date().toISOString();
                const errorMsg = typeof error === 'string' ? error : (error.message || 'Unknown error');
                const fullContext = context ? `[${context}] ` : '';
                
                // Log to console
                console.error(`CCS Error ${timestamp}: ${fullContext}${errorMsg}`, error);
                
                // Log to server if available
                if (window.ccsAjax?.ajaxUrl) {
                    this.logToServer(errorMsg, context, error);
                }
                
                // Show to user if requested
                if (showUser) {
                    this.showUserError(errorMsg, context);
                }
            },

            /**
             * Log error to server
             */
            logToServer: function(message, context, fullError) {
                $.ajax({
                    url: window.ccsAjax.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'ccs_log_js_error',
                        nonce: window.ccsAjax.logErrorNonce || '',
                        message: message,
                        context: context,
                        details: JSON.stringify(fullError),
                        url: window.location.href,
                        userAgent: navigator.userAgent
                    },
                    timeout: 5000
                }).fail(function() {
                    console.warn('CCS: Failed to log error to server');
                });
            },

            /**
             * Show user-friendly error message
             */
            showUserError: function(message, context) {
                const contextMsg = context ? ` (${context})` : '';
                const userMsg = `Error${contextMsg}: ${message}`;
                
                // Try to find a specific error container first
                let $container = $('#ccs-error-display');
                if (!$container.length) {
                    $container = $('.ccs-admin-container').first();
                }
                
                if ($container.length) {
                    const $errorDiv = $('<div class="notice notice-error is-dismissible"><p></p></div>');
                    $errorDiv.find('p').text(userMsg);
                    $container.prepend($errorDiv);
                    
                    // Auto-remove after 10 seconds
                    setTimeout(() => $errorDiv.fadeOut(), 10000);
                } else {
                    // Fallback to alert
                    alert(userMsg);
                }
            }
        },

        /**
         * AJAX helper with automatic retry and error handling
         */
        ajax: {
            
            /**
             * Make AJAX request with automatic retry
             */
            request: function(options) {
                const defaultOptions = {
                    url: CCSAdmin.config.ajaxUrl,
                    type: 'POST',
                    dataType: 'json',
                    timeout: 30000,
                    retryCount: 0
                };
                
                const settings = $.extend(true, {}, defaultOptions, options);
                
                return $.ajax(settings).fail(function(xhr, status, error) {
                    const context = settings.context || 'AJAX Request';
                    
                    // Retry logic for network errors
                    if (settings.retryCount < CCSAdmin.config.retryAttempts && 
                        (status === 'timeout' || status === 'error')) {
                        
                        settings.retryCount++;
                        console.warn(`CCS: Retrying ${context} (attempt ${settings.retryCount})`);
                        
                        setTimeout(() => {
                            CCSAdmin.ajax.request(settings);
                        }, CCSAdmin.config.retryDelay * settings.retryCount);
                        
                        return;
                    }
                    
                    // Handle final failure
                    const errorMsg = `${context} failed: ${error || status}`;
                    CCSAdmin.errorHandler.handleError(errorMsg, 'AJAX');
                });
            }
        },

        /**
         * UI helpers
         */
        ui: {
            
            /**
             * Show loading state
             */
            showLoading: function($element, message = 'Loading...') {
                $element.prop('disabled', true).text(message);
                if (!$element.hasClass('button-loading')) {
                    $element.addClass('button-loading');
                }
            },

            /**
             * Hide loading state
             */
            hideLoading: function($element, originalText = 'Submit') {
                $element.prop('disabled', false).removeClass('button-loading').text(originalText);
            },

            /**
             * Show success message
             */
            showSuccess: function(message, $container) {
                if (!$container) {
                    $container = $('.ccs-admin-container').first();
                }
                
                const $successDiv = $('<div class="notice notice-success is-dismissible"><p></p></div>');
                $successDiv.find('p').text(message);
                $container.prepend($successDiv);
                
                // Auto-remove after 5 seconds
                setTimeout(() => $successDiv.fadeOut(), 5000);
            }
        },

        /**
         * Initialize admin functionality
         */
        init: function() {
            console.log('CCS Admin: Initializing core module');
            
            // Verify AJAX object
            if (!window.ccsAjax || !window.ccsAjax.ajaxUrl) {
                this.errorHandler.handleError('AJAX configuration missing', 'Initialization', false);
                return;
            }
            
            this.config.ajaxUrl = window.ccsAjax.ajaxUrl;
            
            // Set up global error handling
            window.addEventListener('error', (event) => {
                this.errorHandler.handleError(event.error, 'Global Error', false);
            });
            
            // Handle unhandled promise rejections
            window.addEventListener('unhandledrejection', (event) => {
                this.errorHandler.handleError(event.reason, 'Unhandled Promise', false);
            });
            
            console.log('CCS Admin: Core module initialized successfully');
        }
    };

    // Initialize when DOM is ready
    $(document).ready(function() {
        CCSAdmin.init();
    });

})(jQuery);