<?php
/**
 * Database Manager for Canvas Course Sync - FIXED VERSION
 * Handles database operations with proper error handling and transaction safety
 *
 * @package Canvas_Course_Sync
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CCS_Database_Manager {

	/**
	 * Logger instance
	 */
	private $logger;

	/**
	 * Tracking table name
	 */
	public $table_name;

	/**
	 * Error flag
	 */
	private $has_errors = false;

	/**
	 * Constructor with dependency injection and error handling
	 *
	 * @param CCS_Logger|null $logger Logger instance (optional)
	 */
	public function __construct( CCS_Logger $logger = null ) {
		global $wpdb;

		$this->logger = $logger;
		$this->table_name = $wpdb->prefix . 'ccs_course_tracking';
		
		// Create table with error handling
		$this->safe_create_course_tracking_table();
	}

	/**
	 * Safe error logging
	 */
	private function log_error( $message ) {
		if ( $this->logger ) {
			$this->logger->log( $message, 'error' );
		}
		
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( 'CCS_Database_Manager Error: ' . $message );
		}
		
		$this->has_errors = true;
	}

	/**
	 * Check if database manager has errors
	 */
	public function has_errors() {
		return $this->has_errors;
	}

	/**
	 * Create course tracking table with comprehensive error handling
	 */
	private function safe_create_course_tracking_table() {
		global $wpdb;

		try {
			// Check if table already exists
			$table_exists = $wpdb->get_var( $wpdb->prepare( 
				"SHOW TABLES LIKE %s", 
				$this->table_name 
			) );

			if ( $table_exists ) {
				return true;
			}

			$charset_collate = $wpdb->get_charset_collate();

			$sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
				id mediumint(9) NOT NULL AUTO_INCREMENT,
				canvas_course_id bigint DEFAULT NULL,
				catalog_course_id bigint DEFAULT NULL,
				wordpress_post_id bigint DEFAULT NULL,
				course_title varchar(255) DEFAULT NULL,
				course_slug varchar(255) DEFAULT NULL,
				course_description text NULL,
				course_short_description text NULL,
				sync_status varchar(50) DEFAULT 'synced',
				created_at datetime DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY canvas_course_id (canvas_course_id),
				KEY catalog_course_id (catalog_course_id),
				KEY wordpress_post_id (wordpress_post_id),
				KEY course_slug (course_slug),
				KEY course_title (course_title(191)),
				KEY sync_status (sync_status)
			) {$charset_collate};";

			if ( ! function_exists( 'dbDelta' ) ) {
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			}

			$result = dbDelta( $sql );

			// Verify table was created
			$table_created = $wpdb->get_var( $wpdb->prepare( 
				"SHOW TABLES LIKE %s", 
				$this->table_name 
			) );

			if ( ! $table_created ) {
				$this->log_error( 'Failed to create tracking table: ' . $this->table_name );
				return false;
			}

			if ( $this->logger ) {
				$this->logger->log( 'Course tracking table created/verified successfully' );
			}

			return true;

		} catch ( Exception $e ) {
			$this->log_error( 'Exception creating tracking table: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Check if course exists with enhanced error handling
	 */
	public function course_exists( $catalog_id, $course_title = '' ) {
		global $wpdb;

		try {
			// Validate inputs
			$catalog_id = intval( $catalog_id );
			if ( $catalog_id <= 0 ) {
				return array( 'exists' => false, 'error' => 'Invalid catalog ID' );
			}

			// Check custom tracking table first
			$existing_tracking = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE catalog_course_id = %d",
				$catalog_id
			) );

			if ( $wpdb->last_error ) {
				$this->log_error( 'Database error in course_exists (tracking): ' . $wpdb->last_error );
				// Continue with other checks instead of failing completely
			}

			if ( $existing_tracking ) {
				// Verify the WordPress post still exists and is not trashed
				if ( $existing_tracking->sync_status === 'synced' && $existing_tracking->wordpress_post_id ) {
					$post = get_post( $existing_tracking->wordpress_post_id );
					if ( $post && $post->post_status !== 'trash' ) {
						return array(
							'exists' => true,
							'type' => 'tracking_table',
							'post_id' => $existing_tracking->wordpress_post_id,
							'data' => $existing_tracking,
						);
					}
				}
			}

			// Check WordPress posts meta with error handling
			$existing_by_catalog_id = $wpdb->get_row( $wpdb->prepare(
				"SELECT p.ID, p.post_title FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				 WHERE p.post_type = 'courses'
				 AND pm.meta_key = 'catalog_course_id'
				 AND pm.meta_value = %d
				 AND p.post_status != 'trash'
				 LIMIT 1",
				$catalog_id
			) );

			if ( $wpdb->last_error ) {
				$this->log_error( 'Database error in course_exists (meta): ' . $wpdb->last_error );
			}

			if ( $existing_by_catalog_id ) {
				return array(
					'exists' => true,
					'type' => 'wordpress_meta',
					'post_id' => $existing_by_catalog_id->ID,
					'data' => $existing_by_catalog_id,
				);
			}

			// Check by title if provided
			if ( ! empty( $course_title ) ) {
				$course_title = sanitize_text_field( $course_title );
				
				$existing_by_title = $wpdb->get_row( $wpdb->prepare(
					"SELECT ID, post_title FROM {$wpdb->posts}
					 WHERE post_type = 'courses'
					 AND post_title = %s
					 AND post_status != 'trash'
					 LIMIT 1",
					$course_title
				) );

				if ( $wpdb->last_error ) {
					$this->log_error( 'Database error in course_exists (title): ' . $wpdb->last_error );
				}

				if ( $existing_by_title ) {
					return array(
						'exists' => true,
						'type' => 'wordpress_title',
						'post_id' => $existing_by_title->ID,
						'data' => $existing_by_title,
					);
				}
			}

			return array( 'exists' => false );

		} catch ( Exception $e ) {
			$this->log_error( 'Exception in course_exists: ' . $e->getMessage() );
			return array( 'exists' => false, 'error' => $e->getMessage() );
		}
	}

	/**
	 * Create course with enhanced transaction handling and rollback
	 */
	public function create_course_with_transaction( $course_data ) {
		global $wpdb;

		// Validate required data
		if ( empty( $course_data['title'] ) ) {
			return array(
				'success' => false,
				'error' => 'Course title is required',
			);
		}

		if ( empty( $course_data['catalog_id'] ) ) {
			return array(
				'success' => false,
				'error' => 'Catalog ID is required',
			);
		}

		// Sanitize data
		$course_data = $this->sanitize_course_data( $course_data );

		try {
			// Start transaction with error handling
			$transaction_started = $wpdb->query( 'START TRANSACTION' );
			if ( $transaction_started === false ) {
				throw new Exception( 'Failed to start database transaction: ' . $wpdb->last_error );
			}

			// Double-check for existing course (race condition protection)
			$exists_check = $this->course_exists( $course_data['catalog_id'], $course_data['title'] );
			if ( $exists_check['exists'] ) {
				$wpdb->query( 'ROLLBACK' );
				return array(
					'success' => false,
					'error' => 'Course already exists',
					'existing_post_id' => $exists_check['post_id'],
				);
			}

			// Create WordPress post
			$post_data = array(
				'post_title' => $course_data['title'],
				'post_content' => $course_data['description'] ?? '',
				'post_excerpt' => $course_data['short_description'] ?? '',
				'post_status' => 'draft',
				'post_type' => 'courses',
				'post_author' => get_current_user_id() ?: 1,
			);

			$post_id = wp_insert_post( $post_data, true );

			if ( is_wp_error( $post_id ) ) {
				throw new Exception( 'Failed to create WordPress post: ' . $post_id->get_error_message() );
			}

			if ( ! $post_id || $post_id === 0 ) {
				throw new Exception( 'wp_insert_post returned invalid post ID' );
			}

			// Add post meta with error checking
			$meta_data = array(
				'canvas_course_id' => intval( $course_data['canvas_id'] ?? 0 ),
				'catalog_course_id' => intval( $course_data['catalog_id'] ),
				'canvas_course_code' => $course_data['course_code'] ?? '',
				'canvas_start_at' => $course_data['start_at'] ?? '',
				'canvas_end_at' => $course_data['end_at'] ?? '',
				'canvas_enrollment_term_id' => intval( $course_data['enrollment_term_id'] ?? 0 ),
				'link' => esc_url_raw( $course_data['enrollment_url'] ?? '' ),
			);

			foreach ( $meta_data as $meta_key => $meta_value ) {
				$meta_result = update_post_meta( $post_id, $meta_key, $meta_value );
				if ( $meta_result === false && ! metadata_exists( 'post', $post_id, $meta_key ) ) {
					throw new Exception( 'Failed to update post meta: ' . $meta_key );
				}
			}

			// Handle tracking table insert/update
			$tracking_result = $this->safe_insert_or_update_tracking( $course_data, $post_id );
			if ( ! $tracking_result['success'] ) {
				throw new Exception( 'Tracking table operation failed: ' . $tracking_result['error'] );
			}

			// Commit transaction
			$commit_result = $wpdb->query( 'COMMIT' );
			if ( $commit_result === false ) {
				throw new Exception( 'Failed to commit transaction: ' . $wpdb->last_error );
			}

			if ( $this->logger ) {
				$this->logger->log( 'Successfully created course: ' . $course_data['title'] . ' (Post ID: ' . $post_id . ')' );
			}

			return array(
				'success' => true,
				'post_id' => $post_id,
				'tracking_id' => $tracking_result['tracking_id'],
			);

		} catch ( Exception $e ) {
			// Rollback transaction
			$wpdb->query( 'ROLLBACK' );
			
			$error_msg = 'Database transaction failed: ' . $e->getMessage();
			$this->log_error( $error_msg );

			return array(
				'success' => false,
				'error' => $error_msg,
			);
		}
	}

	/**
	 * Safely insert or update tracking record
	 */
	private function safe_insert_or_update_tracking( $course_data, $post_id ) {
		global $wpdb;

		try {
			// Check for existing tracking record
			$existing_tracking = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE catalog_course_id = %d OR canvas_course_id = %d",
				intval( $course_data['catalog_id'] ),
				intval( $course_data['canvas_id'] ?? 0 )
			) );

			if ( $wpdb->last_error ) {
				throw new Exception( 'Database error checking existing tracking: ' . $wpdb->last_error );
			}

			if ( $existing_tracking ) {
				// Update existing record
				$update_result = $wpdb->update(
					$this->table_name,
					array(
						'wordpress_post_id' => intval( $post_id ),
						'course_title' => sanitize_text_field( $course_data['title'] ),
						'course_slug' => sanitize_title( $course_data['slug'] ?? '' ),
						'sync_status' => 'synced',
						'updated_at' => current_time( 'mysql' ),
					),
					array( 'id' => $existing_tracking->id ),
					array( '%d', '%s', '%s', '%s', '%s' ),
					array( '%d' )
				);

				if ( $update_result === false ) {
					throw new Exception( 'Failed to update tracking record: ' . $wpdb->last_error );
				}

				return array(
					'success' => true,
					'tracking_id' => $existing_tracking->id,
				);
			} else {
				// Insert new record
				$insert_result = $wpdb->insert(
					$this->table_name,
					array(
						'canvas_course_id' => intval( $course_data['canvas_id'] ?? 0 ),
						'catalog_course_id' => intval( $course_data['catalog_id'] ),
						'wordpress_post_id' => intval( $post_id ),
						'course_title' => sanitize_text_field( $course_data['title'] ),
						'course_slug' => sanitize_title( $course_data['slug'] ?? '' ),
						'course_description' => sanitize_textarea_field( $course_data['description'] ?? '' ),
						'course_short_description' => sanitize_textarea_field( $course_data['short_description'] ?? '' ),
						'sync_status' => 'synced',
					),
					array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
				);

				if ( $insert_result === false ) {
					throw new Exception( 'Failed to insert tracking record: ' . $wpdb->last_error );
				}

				return array(
					'success' => true,
					'tracking_id' => $wpdb->insert_id,
				);
			}

		} catch ( Exception $e ) {
			return array(
				'success' => false,
				'error' => $e->getMessage(),
			);
		}
	}

	/**
	 * Sanitize course data
	 */
	private function sanitize_course_data( $course_data ) {
		return array(
			'canvas_id' => isset( $course_data['canvas_id'] ) ? intval( $course_data['canvas_id'] ) : 0,
			'catalog_id' => isset( $course_data['catalog_id'] ) ? intval( $course_data['catalog_id'] ) : 0,
			'title' => isset( $course_data['title'] ) ? sanitize_text_field( $course_data['title'] ) : '',
			'description' => isset( $course_data['description'] ) ? wp_kses_post( $course_data['description'] ) : '',
			'short_description' => isset( $course_data['short_description'] ) ? sanitize_textarea_field( $course_data['short_description'] ) : '',
			'course_code' => isset( $course_data['course_code'] ) ? sanitize_text_field( $course_data['course_code'] ) : '',
			'start_at' => isset( $course_data['start_at'] ) ? sanitize_text_field( $course_data['start_at'] ) : '',
			'end_at' => isset( $course_data['end_at'] ) ? sanitize_text_field( $course_data['end_at'] ) : '',
			'enrollment_term_id' => isset( $course_data['enrollment_term_id'] ) ? intval( $course_data['enrollment_term_id'] ) : 0,
			'enrollment_url' => isset( $course_data['enrollment_url'] ) ? esc_url_raw( $course_data['enrollment_url'] ) : '',
			'slug' => isset( $course_data['slug'] ) ? sanitize_title( $course_data['slug'] ) : '',
		);
	}

	/**
	 * Get course sync statistics with error handling
	 */
	public function get_sync_stats() {
		global $wpdb;

		try {
			$stats = $wpdb->get_row(
				"SELECT
					COUNT(*) as total_synced,
					COUNT(CASE WHEN sync_status = 'synced' THEN 1 END) as active_synced,
					COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as synced_today
				FROM {$this->table_name}"
			);

			if ( $wpdb->last_error ) {
				$this->log_error( 'Database error in get_sync_stats: ' . $wpdb->last_error );
				return array(
					'total_synced' => 0,
					'active_synced' => 0,
					'synced_today' => 0,
				);
			}

			return $stats ? (array) $stats : array(
				'total_synced' => 0,
				'active_synced' => 0,
				'synced_today' => 0,
			);

		} catch ( Exception $e ) {
			$this->log_error( 'Exception in get_sync_stats: ' . $e->getMessage() );
			return array(
				'total_synced' => 0,
				'active_synced' => 0,
				'synced_today' => 0,
			);
		}
	}

	/**
	 * Cleanup deleted courses with enhanced error handling
	 */
	public function cleanup_deleted_courses() {
		global $wpdb;

		$results = array(
			'checked' => 0,
			'updated' => 0,
			'details' => array(),
		);

		try {
			// Get all tracked courses with 'synced' status
			$synced_courses = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$this->table_name} WHERE sync_status = %s LIMIT 100",
					'synced'
				)
			);

			if ( $wpdb->last_error ) {
				throw new Exception( 'Database error fetching synced courses: ' . $wpdb->last_error );
			}

			if ( ! is_array( $synced_courses ) ) {
				$synced_courses = array();
			}

			$results['checked'] = count( $synced_courses );

			foreach ( $synced_courses as $tracked_course ) {
				if ( ! is_object( $tracked_course ) || ! isset( $tracked_course->wordpress_post_id ) ) {
					continue;
				}

				// Check if the WordPress post still exists and is not trashed
				$post = get_post( $tracked_course->wordpress_post_id );
				$should_update = false;
				$status = '';

				if ( ! $post ) {
					$should_update = true;
					$status = 'deleted';
				} elseif ( $post->post_status === 'trash' ) {
					$should_update = true;
					$status = 'trashed';
				}

				if ( $should_update ) {
					// Update sync status to 'available'
					$update_result = $wpdb->update(
						$this->table_name,
						array( 'sync_status' => 'available' ),
						array( 'id' => $tracked_course->id ),
						array( '%s' ),
						array( '%d' )
					);

					if ( $update_result !== false ) {
						$results['updated']++;
						$results['details'][] = array(
							'canvas_id' => $tracked_course->canvas_course_id ?? 0,
							'catalog_id' => $tracked_course->catalog_course_id ?? 0,
							'course_title' => $tracked_course->course_title ?? 'Unknown',
							'post_id' => $tracked_course->wordpress_post_id,
							'status' => $status,
						);

						if ( $this->logger ) {
							$this->logger->log( "Updated course sync status to 'available': {$tracked_course->course_title} (Post was {$status})" );
						}
					} else {
						$this->log_error( 'Failed to update tracking status for course: ' . ( $tracked_course->course_title ?? 'Unknown' ) );
					}
				}
			}

			if ( $this->logger ) {
				$this->logger->log( "Cleanup completed: {$results['updated']} courses updated from 'synced' to 'available'" );
			}

		} catch ( Exception $e ) {
			$this->log_error( 'Exception in cleanup_deleted_courses: ' . $e->getMessage() );
		}

		return $results;
	}

	/**
	 * Test database connection
	 */
	public function test_database_connection() {
		global $wpdb;

		try {
			$result = $wpdb->get_var( "SELECT 1" );
			
			if ( $wpdb->last_error ) {
				throw new Exception( 'Database error: ' . $wpdb->last_error );
			}

			return $result === '1';

		} catch ( Exception $e ) {
			$this->log_error( 'Database connection test failed: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Get table status for debugging
	 */
	public function get_table_status() {
		global $wpdb;

		try {
			$table_exists = $wpdb->get_var( $wpdb->prepare(
				"SHOW TABLES LIKE %s",
				$this->table_name
			) );

			$row_count = 0;
			if ( $table_exists ) {
				$row_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_name}" );
			}

			return array(
				'table_exists' => (bool) $table_exists,
				'table_name' => $this->table_name,
				'row_count' => intval( $row_count ),
				'has_errors' => $this->has_errors,
				'last_error' => $wpdb->last_error,
			);

		} catch ( Exception $e ) {
			$this->log_error( 'Exception getting table status: ' . $e->getMessage() );
			return array(
				'table_exists' => false,
				'table_name' => $this->table_name,
				'row_count' => 0,
				'has_errors' => true,
				'last_error' => $e->getMessage(),
			);
		}
	}
}
