
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
        
        $this->log_file = $log_dir . '/ccs-log-' . date('Y-m-d') . '.log';
    }

    /**
     * Log a message
     *
     * @param string $message Message to log
     * @param string $level Log level (info, warning, error)
     */
    public function log($message, $level = 'info') {
        $timestamp = date('Y-m-d H:i:s');
        $log_entry = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
        
        file_put_contents($this->log_file, $log_entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Get log entries
     *
     * @param int $limit Number of entries to retrieve
     * @return array Log entries
     */
    public function get_logs($limit = 100) {
        if (!file_exists($this->log_file)) {
            return array();
        }

        $logs = file($this->log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        if (!$logs) {
            return array();
        }

        // Return the last N entries
        return array_slice(array_reverse($logs), 0, $limit);
    }

    /**
     * Clear all logs
     *
     * @return bool Success status
     */
    public function clear_logs() {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/canvas-course-sync/logs';
        
        if (!is_dir($log_dir)) {
            return true;
        }

        $files = glob($log_dir . '/*.log');
        
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        return true;
    }
}
