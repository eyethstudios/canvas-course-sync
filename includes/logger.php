
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
        $this->create_table_if_not_exists();
    }
    
    /**
     * Create logs table if it doesn't exist
     */
    private function create_table_if_not_exists() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
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
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$this->table_name}");
    }
}
