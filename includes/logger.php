
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
        
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
        }
        
        $this->log_file = $log_dir . '/canvas-sync.log';
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
        
        error_log($log_entry, 3, $this->log_file);
    }

    /**
     * Get recent log entries
     *
     * @param int $lines Number of lines to retrieve
     * @return array Array of log entries
     */
    public function get_recent_logs($lines = 50) {
        if (!file_exists($this->log_file)) {
            return array();
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
            return unlink($this->log_file);
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
}
