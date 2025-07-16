<?php
/**
 * Course Importer class for Canvas Course Sync
 *
 * @package Canvas_Course_Sync
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Course Importer class
 */
class CCS_Importer {

	/**
	 * Logger instance
	 */
	private $logger;

	/**
	 * Catalog API instance
	 */
	private $catalogApi;

	/**
	 * Media handler instance
	 */
	private $media_handler;

	/**
	 * Database manager instance
	 */
	private $db_manager;

	/**
	 * Slug generator instance
	 */
	private $slug_generator;

	/**
	 * Constructor with dependency injection
	 *
	 * @param CCS_Logger           $logger Logger instance
	 * @param CCS_Catalog_API      $catalogApi Catalog API instance
	 * @param CCS_Media_Handler    $media_handler Media handler instance
	 * @param CCS_Database_Manager $db_manager Database manager instance
	 * @param CCS_Slug_Generator   $slug_generator Slug generator instance
	 */
	public function __construct(
		CCS_Logger $logger,
		CCS_Catalog_API $catalogApi,
		CCS_Media_Handler $media_handler,
		CCS_Database_Manager $db_manager,
		CCS_Slug_Generator $slug_generator
	) {
		$this->logger         = $logger;
		$this->catalogApi     = $catalogApi;
		$this->media_handler  = $media_handler;
		$this->db_manager     = $db_manager;
		$this->slug_generator = $slug_generator;
	}


	/**
	 * Check if a course exists
	 *
	 * @param int    $course_id Canvas course ID
	 * @param string $course_name Course name
	 * @return array Course exists check result
	 */
	public function course_exists( $course_id, $course_name = '' ) {
		return $this->db_manager->course_exists( $course_id, $course_name );
	}

	/**
	 * Import courses from Canvas
	 *
	 * @param array $catalog_ids Array of catalog IDs to import
	 * @return array Import results
	 */
	public function import_courses( $catalog_ids ) {
		error_log( 'CCS_Importer: import_courses() called at ' . current_time( 'mysql' ) );
		error_log( 'CCS_Importer: Catalog IDs to import: ' . print_r( $catalog_ids, true ) );

		if ( ! $this->catalogApi ) {
			error_log( 'CCS_Importer: ERROR - Catalog API not properly initialized' );
			throw new Exception( __( 'Catalog API not properly initialized.', 'canvas-course-sync' ) );
		}

		$results = array(
			'imported' => 0,
			'skipped'  => 0,
			'errors'   => 0,
			'total'    => count( $catalog_ids ),
			'message'  => '',
			'details'  => array(),
		);

		error_log( 'CCS_Importer: Starting import of ' . count( $catalog_ids ) . ' courses' );

		foreach ( $catalog_ids as $index => $catalog_id ) {
			error_log( 'CCS_Importer: Processing course ' . ( $index + 1 ) . ' of ' . count( $catalog_ids ) . ' - Catalog ID: ' . $catalog_id );

			// Update sync status
			set_transient(
				'ccs_sync_status',
				array(
					'status'    => sprintf( __( 'Processing course %1$d of %2$d...', 'canvas-course-sync' ), $index + 1, count( $catalog_ids ) ),
					'processed' => $index,
					'total'     => count( $catalog_ids ),
				),
				300
			);

			try {
				// Check for existing course
				error_log( 'CCS_Importer: Checking for existing course with Catalog ID: ' . $catalog_id );

				$exists_check = $this->db_manager->course_exists( $catalog_id );

				if ( $exists_check['exists'] ) {
					// Check if the existing post is actually available
					$existing_post = get_post( $exists_check['post_id'] );
					if ( $existing_post && $existing_post->post_status !== 'trash' ) {
						error_log( 'CCS_Importer: DUPLICATE FOUND - Course already exists and is active: ' . $exists_check['type'] );
						error_log( 'CCS_Importer: Existing data: ' . print_r( $exists_check['data'], true ) );
						++$results['skipped'];
						$results['details'][] = array(
							'course_id'        => $catalog_id,
							'status'           => 'skipped',
							'reason'           => 'Already exists (' . $exists_check['type'] . ')',
							'existing_post_id' => $exists_check['post_id'],
						);
						if ( $this->logger ) {
							$this->logger->log( 'Course already exists (' . $exists_check['type'] . '): ' . $catalog_id . ' - Post ID: ' . $exists_check['post_id'] );
						}
						continue;
					} else {
						error_log( 'CCS_Importer: Course tracking exists but post is deleted/trashed - will re-import with fresh content' );
						// Continue with import process to recreate the course with fresh Canvas content
					}
				}

				error_log( 'CCS_Importer: No existing course found, fetching course details...' );
				$course_details = $this->catalogApi->get_course_by_catalog_id( $catalog_id );

				if ( is_wp_error( $course_details ) ) {
					error_log( 'CCS_Importer: ERROR - Failed to get course details: ' . $course_details->get_error_message() );
					++$results['errors'];
					$error_msg            = 'Failed to get course details for ID ' . $catalog_id . ': ' . $course_details->get_error_message();
					$results['details'][] = array(
						'course_id' => $catalog_id,
						'status'    => 'error',
						'reason'    => $error_msg,
					);
					if ( $this->logger ) {
						$this->logger->log( $error_msg, 'error' );
					}
					continue;
				}

				error_log( 'CCS_Importer: Course details received for: ' . ( $course_details['title'] ?? 'Unknown' ) );
				error_log( 'CCS_Importer: Full course details: ' . print_r( $course_details, true ) );

				$course_name = isset( $course_details['title'] ) ? trim( $course_details['title'] ) : 'Untitled Course';

				// Generate slug
				$slug_result = $this->slug_generator->generate_course_slug( $course_name, $catalog_id );

				if ( ! $slug_result['success'] ) {
					error_log( 'CCS_Importer: ERROR - Slug generation failed: ' . $slug_result['error'] );
					++$results['errors'];
					$results['details'][] = array(
						'course_id' => $catalog_id,
						'status'    => 'error',
						'reason'    => 'Slug generation failed: ' . $slug_result['error'],
					);
					continue;
				}

				$course_slug    = $slug_result['slug'];
				$enrollment_url = $course_details['listing_url'] ?? '';

				// Create course using database manager transaction
				error_log( 'CCS_Importer: Creating course using transaction...' );
				$course_data = array(
					'canvas_id'          => $course_details['canvas_course']['id'],
					'catalog_id'         => $catalog_id,
					'title'              => $course_name,
					'description'        => $course_details['description'] ?? '',
					'short_description'  => $course_details['short_description'] ?? '',
					'course_code'        => $course_details['course_code'] ?? '',
					'start_at'           => $course_details['start_date'] ?? '',
					'end_at'             => $course_details['end_date'] ?? '',
					'enrollment_term_id' => $course_details['enrollment_term_id'] ?? 0,
					'enrollment_url'     => $enrollment_url,
					'slug'               => $course_slug,
				);

				$create_result = $this->db_manager->create_course_with_transaction( $course_data );

				if ( ! $create_result['success'] ) {
					error_log( 'CCS_Importer: ERROR - Transaction failed: ' . $create_result['error'] );
					++$results['errors'];
					$results['details'][] = array(
						'course_id' => $catalog_id,
						'status'    => 'error',
						'reason'    => 'Database transaction failed: ' . $create_result['error'],
					);
					continue;
				}

				$post_id = $create_result['post_id'];

				if ( is_wp_error( $post_id ) ) {
					error_log( 'CCS_Importer: ERROR - Failed to create post: ' . $post_id->get_error_message() );
					++$results['errors'];
					$results['details'][] = array(
						'course_id' => $catalog_id,
						'status'    => 'error',
						'reason'    => 'Failed to create post: ' . $post_id->get_error_message(),
					);
					continue;
				}

				error_log( 'CCS_Importer: ✓ Course created as DRAFT - Post ID: ' . $post_id );

				// Handle course image
				if ( ! empty( $course_details['listing_image'] ) && $this->media_handler ) {
					$image_result = $this->media_handler->set_featured_image( $post_id, $course_details['listing_image'], $course_name );
					if ( $image_result ) {
						error_log( 'CCS_Importer: ✓ Featured image set successfully' );
					}
				}

				++$results['imported'];
				$results['details'][] = array(
					'course_id'   => $catalog_id,
					'status'      => 'imported',
					'post_id'     => $post_id,
					'title'       => $course_name,
					'slug'        => $course_slug,
					'url'         => $enrollment_url,
					'post_status' => 'draft',
				);

				error_log( 'CCS_Importer: ✓ Course import completed - Post ID: ' . $post_id . ' (DRAFT)' );
				if ( $this->logger ) {
					$this->logger->log( 'Successfully imported course as draft: ' . $course_name . ' (Post ID: ' . $post_id . ')' );
				}
			} catch ( Exception $e ) {
				++$results['errors'];
				$error_msg = 'Exception processing Catalog ID ' . $catalog_id . ': ' . $e->getMessage();
				error_log( 'CCS_Importer: EXCEPTION - ' . $error_msg );
				$results['details'][] = array(
					'course_id' => $catalog_id,
					'status'    => 'error',
					'reason'    => $error_msg,
				);
				if ( $this->logger ) {
					$this->logger->log( $error_msg, 'error' );
				}
			}
		}

		$results['message'] = sprintf(
			__( 'Import completed: %1$d imported as drafts, %2$d skipped, %3$d errors', 'canvas-course-sync' ),
			$results['imported'],
			$results['skipped'],
			$results['errors']
		);

		error_log( 'CCS_Importer: Import process completed - Results: ' . print_r( $results, true ) );

		return $results;
	}

	/**
	 * Verify meta fields were updated correctly
	 */
	private function verify_meta_fields( $post_id, $course_data ) {
		error_log( 'CCS_Importer: ✓ VERIFYING META FIELDS for Post ID: ' . $post_id );

		$expected_meta = array(
			'canvas_course_id'          => intval( $course_data['canvas_id'] ),
			'catalog_course_id'         => intval( $course_data['catalog_id'] ),
			'canvas_course_code'        => $course_data['course_code'],
			'canvas_start_at'           => $course_data['start_at'],
			'canvas_end_at'             => $course_data['end_at'],
			'canvas_enrollment_term_id' => intval( $course_data['enrollment_term_id'] ),
			'link'                      => $course_data['enrollment_url'],
		);

		$verification_results = array();

		foreach ( $expected_meta as $meta_key => $expected_value ) {
			$actual_value = get_post_meta( $post_id, $meta_key, true );
			$matches      = ( $actual_value == $expected_value );

			$verification_results[ $meta_key ] = array(
				'expected' => $expected_value,
				'actual'   => $actual_value,
				'matches'  => $matches,
			);

			if ( $matches ) {
				error_log( 'CCS_Importer: ✓ Meta field verified: ' . $meta_key . ' = ' . $actual_value );
			} else {
				error_log( 'CCS_Importer: ✗ Meta field mismatch: ' . $meta_key . ' - Expected: ' . $expected_value . ', Actual: ' . $actual_value );
			}
		}

		error_log( 'CCS_Importer: Meta field verification complete: ' . print_r( $verification_results, true ) );

		return $verification_results;
	}
}
