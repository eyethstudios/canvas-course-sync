
<?php
/**
 * Logger class for Canvas Course Sync
 *
 * @package Canvas_Course_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Logger class
 */
class CCS_Logger {
    
    /**
     * Log directory
     */
    private $log_dir;
    
    /**
     * Constructor
     */
    public function __construct() {
        $upload_dir = wp_upload_dir();
        $this->log_dir = $upload_dir['basedir'] . '/canvas-course-sync/logs';
        $this->ensure_log_dir();
    }
    
    /**
     * Ensure log directory exists
     */
    private function ensure_log_dir() {
        if (!file_exists($this->log_dir)) {
            wp_mkdir_p($this->log_dir);
            
            // Add .htaccess for security
            $htaccess_content = "deny from all\n";
            file_put_contents($this->log_dir . '/.htaccess', $htaccess_content);
        }
    }
    
    /**
     * Log a message
     *
     * @param string $message Message to log
     * @param string $level Log level (info, warning, error)
     */
    public function log($message, $level = 'info') {
        $timestamp = current_time('Y-m-d H:i:s');
        $log_entry = sprintf("[%s] [%s] %s\n", $timestamp, strtoupper($level), $message);
        
        $log_file = $this->log_dir . '/canvas-sync-' . date('Y-m-d') . '.log';
        file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Get recent log entries
     *
     * @param int $limit Number of entries to return
     * @return array Log entries
     */
    public function get_recent_logs($limit = 100) {
        $log_file = $this->log_dir . '/canvas-sync-' . date('Y-m-d') . '.log';
        
        if (!file_exists($log_file)) {
            return array();
        }
        
        $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return array_slice(array_reverse($lines), 0, $limit);
    }
    
    /**
     * Clear logs
     */
    public function clear_logs() {
        $files = glob($this->log_dir . '/*.log');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}
