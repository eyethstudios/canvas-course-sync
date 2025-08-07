<?php
/**
 * Logger class for Canvas Course Sync - FIXED VERSION
 * Enhanced with memory management and error handling
 *
 * @package Canvas_Course_Sync
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enhanced Logger class with crash prevention
 */
class CCS_Logger {

	/**
	 * Log table name
	 */
	public $table_name;

	/**
	 * Table verification flag
	 */
	private $table_verified = false;

	/**
	 * Maximum log entries to keep
	 */
	private $max_log_entries = 1000;

	/**
	 * Batch insert threshold
	 */
	private $batch_threshold = 50;

	/**
	 * Pending log entries for batch insert
	 */
	private $pending_logs = array();

	/**
	 * Initialization error flag
	 */
	private $init_error = false;

	/**
	 * Constructor with error handling
	 */
	public function __construct() {
		global $wpdb;
		
		try {
			$this->table_name = $wpdb->prefix . 'ccs_logs';
			
			// Register shutdown function to handle pending logs
			register_shutdown_function( array( $this, 'flush_pending_logs' ) );
			
		} catch ( Exception $e ) {
			$this->init_error = true;
			$this->safe_error_log( 'Logger initialization failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Safe error logging that won't cause crashes
	 */
	private function safe_error_log( $message ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG && function_exists( 'error_log' ) ) {
			try {
				error_log( 'CCS Logger: ' . $message );
			} catch ( Exception $e ) {
				// Fail silently to prevent crashes
			}
		}
	}

	/**
	 * Public method to ensure table exists (for activation)
	 */
	public function ensure_table_exists() {
		return $this->create_table_if_not_exists( true );
	}

	/**
	 * Create logs table with comprehensive error handling
	 */
	private function create_table_if_not_exists( $force = false ) {
		// Skip if initialization failed
		if ( $this->init_error ) {
			return false;
		}

		// Skip check if already verified and not forced
		if ( ! $force && $this->table_verified ) {
			return true;
		}

		global $wpdb;

		try {
			// Check if table exists
			$table_exists = $wpdb->get_var( $wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$this->table_name
			) );

			if ( $table_exists ) {
				$this->table_verified = true;
				return true;
			}

			$charset_collate = $wpdb->get_charset_collate();

			$sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
				id int(11) NOT NULL AUTO_INCREMENT,
				timestamp datetime DEFAULT CURRENT_TIMESTAMP,
				level varchar(20) NOT NULL DEFAULT 'info',
				message text NOT NULL,
				PRIMARY KEY (id),
				KEY level_idx (level),
				KEY timestamp_idx (timestamp)
			) {$charset_collate};";

			if ( ! function_exists( 'dbDelta' ) ) {
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			}

			$result = dbDelta( $sql );

			// Verify table was created
			$table_created = $wpdb->get_var( $wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$this->table_name
			) );

			if ( ! $table_created ) {
				$this->safe_error_log( 'Failed to create table ' . $this->table_name );
				return false;
			}

			$this->table_verified = true;
			return true;

		} catch ( Exception $e ) {
			$this->safe_error_log( 'Exception creating table: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Log a message with enhanced error handling
	 */
	public function log( $message, $level = 'info' ) {
		// Skip if initialization failed
		if ( $this->init_error ) {
			$this->safe_error_log( 'Skipping log due to init error: ' . $message );
			return false;
		}

		// Validate and sanitize inputs
		if ( empty( $message ) || ! is_string( $message ) ) {
			return false;
		}

		// Truncate very long messages to prevent memory issues
		if ( strlen( $message ) > 5000 ) {
			$message = substr( $message, 0, 5000 ) . '... (truncated)';
		}

		$message = sanitize_textarea_field( $message );
		$level = $this->sanitize_log_level( $level );

		// Try immediate insert first, fall back to batch if needed
		if ( count( $this->pending_logs ) < $this->batch_threshold ) {
			$success = $this->immediate_log( $message, $level );
			if ( $success ) {
				return true;
			}
		}

		// Add to pending logs for batch processing
		$this->add_to_pending_logs( $message, $level );
		return true;
	}

	/**
	 * Immediate log insert
	 */
	private function immediate_log( $message, $level ) {
		if ( ! $this->create_table_if_not_exists() ) {
			return false;
		}

		global $wpdb;

		try {
			$result = $wpdb->insert(
				$this->table_name,
				array(
					'message' => $message,
					'level' => $level,
					'timestamp' => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s' )
			);

			if ( $result === false ) {
				$this->safe_error_log( 'Database insert failed: ' . $wpdb->last_error );
				return false;
			}

			// Cleanup old logs periodically
			if ( rand( 1, 100 ) <= 5 ) { // 5% chance
				$this->cleanup_old_logs();
			}

			return true;

		} catch ( Exception $e ) {
			$this->safe_error_log( 'Exception in immediate_log: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Add log to pending batch
	 */
	private function add_to_pending_logs( $message, $level ) {
		$this->pending_logs[] = array(
			'message' => $message,
			'level' => $level,
			'timestamp' => current_time( 'mysql' ),
		);

		// Process batch if threshold reached
		if ( count( $this->pending_logs ) >= $this->batch_threshold ) {
			$this->flush_pending_logs();
		}
	}

	/**
	 * Flush pending logs in batch
	 */
	public function flush_pending_logs() {
		if ( empty( $this->pending_logs ) || $this->init_error ) {
			return;
		}

		if ( ! $this->create_table_if_not_exists() ) {
			$this->pending_logs = array(); // Clear to prevent memory buildup
			return;
		}

		global $wpdb;

		try {
			// Build bulk insert query
			$values = array();
			$placeholders = array();

			foreach ( $this->pending_logs as $log ) {
				$values[] = $log['message'];
				$values[] = $log['level'];
				$values[] = $log['timestamp'];
				$placeholders[] = '(%s, %s, %s)';
			}

			if ( ! empty( $placeholders ) ) {
				$sql = "INSERT INTO {$this->table_name} (message, level, timestamp) VALUES " 
					. implode( ', ', $placeholders );

				$result = $wpdb->query( $wpdb->prepare( $sql, $values ) );

				if ( $result === false ) {
					$this->safe_error_log( 'Batch insert failed: ' . $wpdb->last_error );
				}
			}

		} catch ( Exception $e ) {
			$this->safe_error_log( 'Exception in flush_pending_logs: ' . $e->getMessage() );
		}

		// Clear pending logs regardless of success to prevent memory buildup
		$this->pending_logs = array();
	}

	/**
	 * Sanitize log level
	 */
	private function sanitize_log_level( $level ) {
		$valid_levels = array( 'info', 'warning', 'error', 'debug' );
		$level = strtolower( trim( $level ) );
		
		return in_array( $level, $valid_levels, true ) ? $level : 'info';
	}

	/**
	 * Get recent log entries with pagination and limits
	 */
	public function get_recent_logs( $limit = 100, $level = '', $offset = 0 ) {
		if ( $this->init_error ) {
			return array();
		}

		if ( ! $this->create_table_if_not_exists() ) {
			return array();
		}

		global $wpdb;

		try {
			// Sanitize inputs
			$limit = min( max( 1, intval( $limit ) ), 500 ); // Cap at 500
			$offset = max( 0, intval( $offset ) );

			$where_clause = '';
			$prepare_args = array( $limit );

			if ( ! empty( $level ) ) {
				$level = $this->sanitize_log_level( $level );
				$where_clause = ' WHERE level = %s';
				array_unshift( $prepare_args, $level );
			}

			if ( $offset > 0 ) {
				$sql = "SELECT * FROM {$this->table_name}{$where_clause} 
						ORDER BY timestamp DESC LIMIT %d OFFSET %d";
				$prepare_args[] = $offset;
			} else {
				$sql = "SELECT * FROM {$this->table_name}{$where_clause} 
						ORDER BY timestamp DESC LIMIT %d";
			}

			$results = $wpdb->get_results( $wpdb->prepare( $sql, $prepare_args ) );

			if ( $wpdb->last_error ) {
				$this->safe_error_log( 'Database error in get_recent_logs: ' . $wpdb->last_error );
				return array();
			}

			return is_array( $results ) ? $results : array();

		} catch ( Exception $e ) {
			$this->safe_error_log( 'Exception in get_recent_logs: ' . $e->getMessage() );
			return array();
		}
	}

	/**
	 * Clear logs with enhanced safety
	 */
	public function clear_logs( $level = '' ) {
		if ( $this->init_error ) {
			return false;
		}

		if ( ! $this->create_table_if_not_exists() ) {
			return false;
		}

		// Flush any pending logs first
		$this->flush_pending_logs();

		global $wpdb;

		try {
			if ( empty( $level ) ) {
				// Clear all logs with TRUNCATE for efficiency
				$result = $wpdb->query( "TRUNCATE TABLE {$this->table_name}" );
			} else {
				// Clear logs of specific level
				$level = $this->sanitize_log_level( $level );
				$result = $wpdb->delete(
					$this->table_name,
					array( 'level' => $level ),
					array( '%s' )
				);
			}

			if ( $result === false ) {
				$this->safe_error_log( 'Failed to clear logs: ' . $wpdb->last_error );
				return false;
			}

			return true;

		} catch ( Exception $e ) {
			$this->safe_error_log( 'Exception in clear_logs: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Clean up old logs to prevent database bloat
	 */
	private function cleanup_old_logs() {
		if ( $this->init_error ) {
			return;
		}

		global $wpdb;

		try {
			// Keep only the most recent logs
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$this->table_name} WHERE id NOT IN (
					SELECT id FROM (
						SELECT id FROM {$this->table_name} 
						ORDER BY timestamp DESC 
						LIMIT %d
					) as keeper
				)",
				$this->max_log_entries
			) );

		} catch ( Exception $e ) {
			$this->safe_error_log( 'Exception in cleanup_old_logs: ' . $e->getMessage() );
		}
	}

	/**
	 * Get log statistics with error handling
	 */
	public function get_log_stats() {
		if ( $this->init_error ) {
			return $this->get_default_stats();
		}

		if ( ! $this->create_table_if_not_exists() ) {
			return $this->get_default_stats();
		}

		global $wpdb;

		try {
			$stats = $wpdb->get_results(
				"SELECT level, COUNT(*) as count FROM {$this->table_name} GROUP BY level"
			);

			if ( $wpdb->last_error ) {
				$this->safe_error_log( 'Database error in get_log_stats: ' . $wpdb->last_error );
				return $this->get_default_stats();
			}

			$result = $this->get_default_stats();

			if ( is_array( $stats ) ) {
				foreach ( $stats as $stat ) {
					if ( is_object( $stat ) && isset( $stat->level, $stat->count ) ) {
						$level = $this->sanitize_log_level( $stat->level );
						$count = intval( $stat->count );
						
						if ( isset( $result[ $level ] ) ) {
							$result[ $level ] = $count;
							$result['total'] += $count;
						}
					}
				}
			}

			return $result;

		} catch ( Exception $e ) {
			$this->safe_error_log( 'Exception in get_log_stats: ' . $e->getMessage() );
			return $this->get_default_stats();
		}
	}

	/**
	 * Get default stats structure
	 */
	private function get_default_stats() {
		return array(
			'total' => 0,
			'info' => 0,
			'warning' => 0,
			'error' => 0,
			'debug' => 0,
		);
	}

	/**
	 * Get table health status
	 */
	public function get_table_health() {
		global $wpdb;

		$health = array(
			'table_exists' => false,
			'table_name' => $this->table_name,
			'row_count' => 0,
			'init_error' => $this->init_error,
			'table_verified' => $this->table_verified,
			'pending_logs' => count( $this->pending_logs ),
			'last_error' => '',
		);

		if ( $this->init_error ) {
			$health['last_error'] = 'Logger initialization failed';
			return $health;
		}

		try {
			// Check if table exists
			$table_exists = $wpdb->get_var( $wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$this->table_name
			) );

			$health['table_exists'] = (bool) $table_exists;

			if ( $table_exists ) {
				// Get row count
				$row_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_name}" );
				$health['row_count'] = intval( $row_count );
			}

			if ( $wpdb->last_error ) {
				$health['last_error'] = $wpdb->last_error;
			}

		} catch ( Exception $e ) {
			$health['last_error'] = $e->getMessage();
			$this->safe_error_log( 'Exception in get_table_health: ' . $e->getMessage() );
		}

		return $health;
	}

	/**
	 * Emergency log method that bypasses database
	 */
	public function emergency_log( $message, $level = 'error' ) {
		$timestamp = date( 'Y-m-d H:i:s' );
		$log_entry = "[{$timestamp}] [{$level}] {$message}";
		
		// Log to PHP error log as fallback
		$this->safe_error_log( 'EMERGENCY LOG: ' . $log_entry );
		
		// Try to write to WordPress debug log if available
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG && defined( 'WP_CONTENT_DIR' ) ) {
			$log_file = WP_CONTENT_DIR . '/debug.log';
			try {
				error_log( $log_entry . "\n", 3, $log_file );
			} catch ( Exception $e ) {
				// Fail silently
			}
		}
	}

	/**
	 * Destructor to ensure pending logs are flushed
	 */
	public function __destruct() {
		try {
			$this->flush_pending_logs();
		} catch ( Exception $e ) {
			$this->safe_error_log( 'Exception in destructor: ' . $e->getMessage() );
		}
	}

	/**
	 * Check if logger is healthy
	 */
	public function is_healthy() {
		return ! $this->init_error && $this->create_table_if_not_exists();
	}

	/**
	 * Reset logger state (for debugging)
	 */
	public function reset_state() {
		$this->table_verified = false;
		$this->init_error = false;
		$this->pending_logs = array();
		
		return $this->create_table_if_not_exists( true );
	}
}
