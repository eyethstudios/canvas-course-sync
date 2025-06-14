
<?php
/**
 * Canvas Course Sync Logger
 *
 * @package Canvas_Course_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Logger class for Canvas Course Sync
 */
class CCS_Logger {
    /**
     * Log file path
     *
     * @var string
     */
    private $log_file;

    /**
     * Constructor
     */
    public function __construct() {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/canvas-course-sync/logs';
        
        // Ensure log directory exists
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
            // Add .htaccess for security
            file_put_contents($log_dir . '/.htaccess', "Order deny,allow\nDeny from all");
        }
        
        $this->log_file = $log_dir . '/canvas-sync.log';
        
        // Test write to ensure logging works
        if (!file_exists($this->log_file)) {
            $this->log('Logger initialized', 'info');
        }
    }

    /**
     * Log a message
     *
     * @param string $message Log message
     * @param string $level Log level (info, warning, error)
     */
    public function log($message, $level = 'info') {
        $timestamp = current_time('Y-m-d H:i:s');
        $log_entry = sprintf("[%s] [%s] %s\n", $timestamp, strtoupper($level), $message);
        
        // Use file_put_contents with append flag for better reliability
        $result = file_put_contents($this->log_file, $log_entry, FILE_APPEND | LOCK_EX);
        
        // Also log to WordPress error log for debugging
        error_log("CCS Logger: " . trim($log_entry));
        
        return $result !== false;
    }

    /**
     * Get recent log entries
     *
     * @param int $lines Number of lines to retrieve
     * @return array Array of log entries
     */
    public function get_recent_logs($lines = 50) {
        if (!file_exists($this->log_file)) {
            // Create initial log entry if file doesn't exist
            $this->log('Log file created', 'info');
        }
        
        $file_content = file_get_contents($this->log_file);
        if (empty($file_content)) {
            return array();
        }
        
        $log_lines = explode("\n", trim($file_content));
        $recent_logs = array_slice($log_lines, -$lines);
        
        return array_reverse($recent_logs);
    }

    /**
     * Clear all logs
     *
     * @return bool True on success, false on failure
     */
    public function clear_logs() {
        if (file_exists($this->log_file)) {
            $result = unlink($this->log_file);
            if ($result) {
                $this->log('Logs cleared', 'info');
            }
            return $result;
        }
        return true;
    }

    /**
     * Get log file size
     *
     * @return string Formatted file size
     */
    public function get_log_file_size() {
        if (!file_exists($this->log_file)) {
            return '0 B';
        }
        
        $size = filesize($this->log_file);
        $units = array('B', 'KB', 'MB', 'GB');
        
        for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
            $size /= 1024;
        }
        
        return round($size, 2) . ' ' . $units[$i];
    }

    /**
     * Get log file path (for direct access if needed)
     *
     * @return string Log file path
     */
    public function get_log_file() {
        return $this->log_file;
    }
}
