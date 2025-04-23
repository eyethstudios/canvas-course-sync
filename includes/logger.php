
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
        
        $this->log_file = $log_dir . '/ccs-' . date('Y-m-d') . '.log';
    }

    /**
     * Log a message
     *
     * @param string $message The message to log
     * @param string $level   The log level (info, warning, error)
     */
    public function log($message, $level = 'info') {
        $timestamp = current_time('mysql');
        $formatted_message = sprintf('[%s] [%s] %s' . PHP_EOL, $timestamp, strtoupper($level), $message);
        
        // Write to log file
        error_log($formatted_message, 3, $this->log_file);
        
        // If this is an error, also write to the WordPress error log
        if ($level === 'error') {
            error_log('Canvas Course Sync: ' . $message);
        }
    }

    /**
     * Get the log file path
     *
     * @return string
     */
    public function get_log_file() {
        return $this->log_file;
    }

    /**
     * Get recent log entries
     *
     * @param int $lines Number of lines to retrieve
     * @return array
     */
    public function get_recent_logs($lines = 100) {
        if (!file_exists($this->log_file)) {
            return array();
        }
        
        $file = new SplFileObject($this->log_file, 'r');
        $file->seek(PHP_INT_MAX); // Seek to the end of file
        $total_lines = $file->key(); // Get total number of lines
        
        $log_entries = array();
        $start_line = max(0, $total_lines - $lines);
        
        $file->seek($start_line);
        
        while (!$file->eof()) {
            $line = $file->fgets();
            if (!empty($line)) {
                $log_entries[] = $line;
            }
        }
        
        return $log_entries;
    }

    /**
     * Clear logs
     *
     * @return boolean
     */
    public function clear_logs() {
        if (file_exists($this->log_file)) {
            return unlink($this->log_file);
        }
        
        return false;
    }
}
