
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
     * Log table name
     */
    private $table_name;
    
    /**
     * Flag to track if table existence has been verified
     */
    private $table_verified = false;
    
    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'ccs_logs';
    }
    
    /**
     * Public method to ensure table exists (for activation)
     */
    public function ensure_table_exists() {
        $this->create_table_if_not_exists(true);
    }
    
    /**
     * Create logs table if it doesn't exist
     *
     * @param bool $force Force table check even if already verified
     */
    private function create_table_if_not_exists($force = false) {
        // Skip check if already verified and not forced
        if (!$force && $this->table_verified) {
            return;
        }
        
        global $wpdb;
        
        // Check if table exists
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $this->table_name
        ));
        
        if ($table_exists) {
            $this->table_verified = true;
            return;
        }
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE {$this->table_name} (
            id int(11) NOT NULL AUTO_INCREMENT,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            level varchar(20) NOT NULL DEFAULT 'info',
            message text NOT NULL,
            PRIMARY KEY (id),
            KEY level (level),
            KEY timestamp (timestamp)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Verify table was created
        $table_created = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $this->table_name
        ));
        
        if (!$table_created) {
            error_log('CCS Logger: Failed to create table ' . $this->table_name);
        } else {
            $this->table_verified = true;
        }
    }
    
    /**
     * Log a message
     *
     * @param string $message Message to log
     * @param string $level Log level (info, warning, error)
     */
    public function log($message, $level = 'info') {
        // Ensure table exists before logging
        $this->create_table_if_not_exists();
        
        global $wpdb;
        
        // Sanitize inputs
        $message = sanitize_textarea_field($message);
        $level = sanitize_key($level);
        
        // Validate log level
        $valid_levels = array('info', 'warning', 'error', 'debug');
        if (!in_array($level, $valid_levels, true)) {
            $level = 'info';
        }
        
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'message' => $message,
                'level' => $level,
                'timestamp' => current_time('mysql')
            ),
            array('%s', '%s', '%s')
        );
        
        // Log to WordPress error log if database insert fails
        if ($result === false) {
            error_log('CCS Logger: Failed to insert log entry - ' . $message);
        }
    }
    
    /**
     * Get recent log entries
     *
     * @param int $limit Number of entries to return
     * @param string $level Optional log level filter
     * @return array Log entries
     */
    public function get_recent_logs($limit = 100, $level = '') {
        // Ensure table exists before querying
        $this->create_table_if_not_exists();
        
        global $wpdb;
        
        // Sanitize inputs
        $limit = absint($limit);
        if ($limit === 0) {
            $limit = 100;
        }
        
        $where_clause = '';
        $prepare_args = array($limit);
        
        if (!empty($level)) {
            $level = sanitize_key($level);
            $where_clause = ' WHERE level = %s';
            array_unshift($prepare_args, $level);
        }
        
        $sql = "SELECT * FROM {$this->table_name}{$where_clause} ORDER BY timestamp DESC LIMIT %d";
        
        $results = $wpdb->get_results(
            $wpdb->prepare($sql, $prepare_args)
        );
        
        return $results ? $results : array();
    }
    
    /**
     * Clear logs
     *
     * @param string $level Optional level to clear (if empty, clears all)
     */
    public function clear_logs($level = '') {
        // Ensure table exists before truncating
        $this->create_table_if_not_exists();
        
        global $wpdb;
        
        if (empty($level)) {
            // Clear all logs
            $wpdb->query("TRUNCATE TABLE {$this->table_name}");
        } else {
            // Clear logs of specific level
            $level = sanitize_key($level);
            $wpdb->delete(
                $this->table_name,
                array('level' => $level),
                array('%s')
            );
        }
    }
    
    /**
     * Get log statistics
     *
     * @return array Statistics
     */
    public function get_log_stats() {
        $this->create_table_if_not_exists();
        
        global $wpdb;
        
        $stats = $wpdb->get_results(
            "SELECT level, COUNT(*) as count FROM {$this->table_name} GROUP BY level"
        );
        
        $result = array(
            'total' => 0,
            'info' => 0,
            'warning' => 0,
            'error' => 0,
            'debug' => 0
        );
        
        if ($stats) {
            foreach ($stats as $stat) {
                $result[$stat->level] = intval($stat->count);
                $result['total'] += intval($stat->count);
            }
        }
        
        return $result;
    }
}
