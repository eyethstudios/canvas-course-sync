
<?php
/**
 * Script and Dependency Verifier for Canvas Course Sync
 * Ensures WordPress admin scripts are properly loaded
 *
 * @package Canvas_Course_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class CCS_Script_Verifier {
    
    /**
     * Logger instance
     */
    private $logger;
    
    /**
     * Constructor
     */
    public function __construct() {
        $canvas_course_sync = canvas_course_sync();
        if ($canvas_course_sync && isset($canvas_course_sync->logger)) {
            $this->logger = $canvas_course_sync->logger;
        }
        
        // Hook into admin scripts
        add_action('admin_enqueue_scripts', array($this, 'verify_admin_scripts'), 9999);
        add_action('admin_footer', array($this, 'output_script_diagnostics'));
        
        error_log('CCS_Script_Verifier: Initialized at ' . current_time('mysql'));
    }
    
    /**
     * Verify admin scripts are properly loaded
     */
    public function verify_admin_scripts($hook) {
        error_log('CCS_Script_Verifier: verify_admin_scripts() called on hook: ' . $hook);
        
        // Check if we're on the Canvas Course Sync admin page
        if (strpos($hook, 'canvas-course-sync') === false) {
            return;
        }
        
        error_log('CCS_Script_Verifier: On Canvas Course Sync admin page');
        
        global $wp_scripts;
        
        // Required dependencies
        $required_scripts = array(
            'jquery',
            'wp-util',
            'wp-ajax-response'
        );
        
        $missing_scripts = array();
        $loaded_scripts = array();
        
        foreach ($required_scripts as $script) {
            if (wp_script_is($script, 'enqueued') || wp_script_is($script, 'registered')) {
                $loaded_scripts[] = $script;
                error_log('CCS_Script_Verifier: ✓ Script loaded: ' . $script);
            } else {
                $missing_scripts[] = $script;
                error_log('CCS_Script_Verifier: ✗ Script missing: ' . $script);
                
                // Try to enqueue missing script
                wp_enqueue_script($script);
                error_log('CCS_Script_Verifier: Attempted to enqueue missing script: ' . $script);
            }
        }
        
        // Check for script conflicts
        $this->check_script_conflicts();
        
        // Verify our custom scripts
        $this->verify_custom_scripts();
        
        // Log summary
        if (!empty($missing_scripts)) {
            $error_msg = 'Missing required scripts: ' . implode(', ', $missing_scripts);
            error_log('CCS_Script_Verifier: ERROR - ' . $error_msg);
            if ($this->logger) {
                $this->logger->log($error_msg, 'error');
            }
        }
        
        if (!empty($loaded_scripts)) {
            error_log('CCS_Script_Verifier: Loaded scripts: ' . implode(', ', $loaded_scripts));
        }
    }
    
    /**
     * Check for script conflicts
     */
    private function check_script_conflicts() {
        global $wp_scripts;
        
        error_log('CCS_Script_Verifier: Checking for script conflicts...');
        
        // Get all enqueued scripts
        $enqueued_scripts = $wp_scripts->queue ?? array();
        
        // Look for potential conflicts
        $potential_conflicts = array();
        
        foreach ($enqueued_scripts as $script_handle) {
            $script_data = $wp_scripts->registered[$script_handle] ?? null;
            
            if ($script_data && isset($script_data->src)) {
                // Check for jQuery conflicts
                if (strpos($script_data->src, 'jquery') !== false && $script_handle !== 'jquery') {
                    $potential_conflicts[] = $script_handle . ' (jQuery conflict)';
                }
                
                // Check for AJAX conflicts
                if (strpos($script_data->src, 'ajax') !== false && strpos($script_handle, 'ccs') === false) {
                    $potential_conflicts[] = $script_handle . ' (AJAX conflict)';
                }
            }
        }
        
        if (!empty($potential_conflicts)) {
            error_log('CCS_Script_Verifier: Potential conflicts detected: ' . implode(', ', $potential_conflicts));
            if ($this->logger) {
                $this->logger->log('Script conflicts detected: ' . implode(', ', $potential_conflicts), 'warning');
            }
        } else {
            error_log('CCS_Script_Verifier: No script conflicts detected');
        }
    }
    
    /**
     * Verify our custom scripts
     */
    private function verify_custom_scripts() {
        error_log('CCS_Script_Verifier: Verifying custom CCS scripts...');
        
        $ccs_scripts = array(
            'ccs-admin',
            'ccs-sync',
            'ccs-courses'
        );
        
        foreach ($ccs_scripts as $script) {
            if (wp_script_is($script, 'enqueued')) {
                error_log('CCS_Script_Verifier: ✓ CCS script loaded: ' . $script);
            } else {
                error_log('CCS_Script_Verifier: ✗ CCS script not loaded: ' . $script);
            }
        }
        
        // Check if ccsAjax is localized
        global $wp_scripts;
        $admin_script = $wp_scripts->registered['ccs-admin'] ?? null;
        
        if ($admin_script && isset($admin_script->extra['data'])) {
            $localized_data = $admin_script->extra['data'];
            if (strpos($localized_data, 'ccsAjax') !== false) {
                error_log('CCS_Script_Verifier: ✓ ccsAjax localization found');
            } else {
                error_log('CCS_Script_Verifier: ✗ ccsAjax localization missing');
            }
        } else {
            error_log('CCS_Script_Verifier: ✗ ccs-admin script or localization data not found');
        }
    }
    
    /**
     * Output script diagnostics in admin footer
     */
    public function output_script_diagnostics() {
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'canvas-course-sync') === false) {
            return;
        }
        
        ?>
        <script type="text/javascript">
        console.log('=== CCS Script Diagnostics ===');
        console.log('jQuery version:', typeof jQuery !== 'undefined' ? jQuery.fn.jquery : 'NOT LOADED');
        console.log('ccsAjax available:', typeof ccsAjax !== 'undefined');
        if (typeof ccsAjax !== 'undefined') {
            console.log('ccsAjax contents:', ccsAjax);
        }
        console.log('WordPress ajaxurl:', typeof ajaxurl !== 'undefined' ? ajaxurl : 'NOT AVAILABLE');
        
        // Check for event handlers
        setTimeout(function() {
            var omitButton = document.getElementById('ccs-omit-selected');
            console.log('Omit button exists:', !!omitButton);
            if (omitButton) {
                console.log('Omit button HTML:', omitButton.outerHTML);
                console.log('Omit button parent:', omitButton.parentElement);
            }
            
            // Check jQuery event handlers
            if (typeof jQuery !== 'undefined' && jQuery._data) {
                var docEvents = jQuery._data(document, 'events');
                console.log('Document event handlers:', docEvents);
            }
        }, 1000);
        
        console.log('=== End CCS Script Diagnostics ===');
        </script>
        <?php
    }
    
    /**
     * Get script verification report
     */
    public function get_verification_report() {
        global $wp_scripts;
        
        $report = array(
            'timestamp' => current_time('mysql'),
            'required_scripts' => array(),
            'custom_scripts' => array(),
            'conflicts' => array(),
            'recommendations' => array()
        );
        
        // Check required scripts
        $required_scripts = array('jquery', 'wp-util', 'wp-ajax-response');
        foreach ($required_scripts as $script) {
            $report['required_scripts'][$script] = wp_script_is($script, 'enqueued');
        }
        
        // Check custom scripts
        $custom_scripts = array('ccs-admin', 'ccs-sync', 'ccs-courses');
        foreach ($custom_scripts as $script) {
            $report['custom_scripts'][$script] = wp_script_is($script, 'enqueued');
        }
        
        // Add recommendations
        if (!wp_script_is('jquery', 'enqueued')) {
            $report['recommendations'][] = 'Enqueue jQuery dependency';
        }
        
        if (!wp_script_is('ccs-admin', 'enqueued')) {
            $report['recommendations'][] = 'Ensure CCS admin scripts are properly enqueued';
        }
        
        return $report;
    }
}
