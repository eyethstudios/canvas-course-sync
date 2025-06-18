
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
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'ccs_logs';
    }
    
    /**
     * Create logs table if it doesn't exist
     */
    private function create_table_if_not_exists() {
        global $wpdb;
        
        // Check if table exists
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $this->table_name
        ));
        
        if ($table_exists) {
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
        
        $wpdb->insert(
            $this->table_name,
            array(
                'message' => sanitize_text_field($message),
                'level' => sanitize_text_field($level),
                'timestamp' => current_time('mysql')
            ),
            array('%s', '%s', '%s')
        );
    }
    
    /**
     * Get recent log entries
     *
     * @param int $limit Number of entries to return
     * @return array Log entries
     */
    public function get_recent_logs($limit = 100) {
        // Ensure table exists before querying
        $this->create_table_if_not_exists();
        
        global $wpdb;
        
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} ORDER BY timestamp DESC LIMIT %d",
                $limit
            )
        );
        
        return $results ? $results : array();
    }
    
    /**
     * Clear logs
     */
    public function clear_logs() {
        // Ensure table exists before truncating
        $this->create_table_if_not_exists();
        
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$this->table_name}");
    }
}
