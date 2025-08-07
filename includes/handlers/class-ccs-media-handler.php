<?php
/**
 * Handles media downloads and WordPress media library integration
 *
 * @package Canvas_Course_Sync
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Media Handler class
 */
class CCS_Media_Handler {
	/**
	 * Logger instance
	 *
	 * @var CCS_Logger
	 */
	private $logger;

	/**
	 * API instance
	 *
	 * @var CCS_Catalog_API
	 */
	private $catalogApi;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->init_dependencies();
	}

	/**
	 * Initialize dependencies safely
	 */
	private function init_dependencies() {
		$canvas_course_sync = canvas_course_sync();

		if ( $canvas_course_sync && isset( $canvas_course_sync->logger ) ) {
			$this->logger = $canvas_course_sync->logger;
		} elseif ( class_exists( 'CCS_Logger' ) ) {
			$this->logger = new CCS_Logger();
		}

		if ( $canvas_course_sync && isset( $canvas_course_sync->catalogApi ) ) {
			$this->catalogApi = $canvas_course_sync->catalogApi;
		} elseif ( class_exists( 'CCS_Canvas_API' ) ) {
			$this->catalogApi = new CCS_Catalog_API( $this->logger );
		}
	}

	/**
	 * Set featured image for a post from Canvas image URL
	 *
	 * @param int    $post_id WordPress post ID
	 * @param string $image_url Canvas image URL
	 * @param string $course_name Course name for filename
	 * @return bool Success status
	 */
	public function set_featured_image( $post_id, $image_url, $course_name ) {
		if ( empty( $image_url ) || empty( $post_id ) ) {
			if ( $this->logger ) {
				$this->logger->log( 'Missing image URL or post ID for featured image', 'error' );
			}
			return false;
		}

		// Require WordPress media functions
		if ( ! function_exists( 'media_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		try {
			// Download the image using Canvas API
			$image_data = null;
			if ( $this->catalogApi && method_exists( $this->catalogApi, 'download_file' ) ) {
				$image_data = $this->catalogApi->download_file( $image_url );
				if ( is_wp_error( $image_data ) ) {
					if ( $this->logger ) {
						$this->logger->log( 'Failed to download image via API: ' . $image_data->get_error_message(), 'error' );
					}
					return false;
				}
			} else {
				// Fallback to wp_remote_get
				$response = wp_remote_get( $image_url, array( 'timeout' => 60 ) );

				if ( is_wp_error( $response ) ) {
					if ( $this->logger ) {
						$this->logger->log( 'Failed to download image: ' . $response->get_error_message(), 'error' );
					}
					return false;
				}

				$image_data = wp_remote_retrieve_body( $response );
			}

			if ( empty( $image_data ) ) {
				if ( $this->logger ) {
					$this->logger->log( 'Downloaded image data is empty', 'error' );
				}
				return false;
			}

			// Get file extension from URL or default to jpg
			$file_extension = 'jpg';
			$url_parts      = wp_parse_url( $image_url );
			if ( isset( $url_parts['path'] ) ) {
				$path_info = pathinfo( $url_parts['path'] );
				if ( isset( $path_info['extension'] ) ) {
					$file_extension = strtolower( $path_info['extension'] );
				}
			}

			// Sanitize course name for filename
			$safe_course_name = sanitize_file_name( $course_name );
			$filename         = 'course-' . $safe_course_name . '-featured.' . $file_extension;

			// Get WordPress upload directory
			$upload_dir = wp_upload_dir();
			$file_path  = $upload_dir['path'] . '/' . $filename;

			// Write image data to file
			$file_written = file_put_contents( $file_path, $image_data );

			if ( $file_written === false ) {
				if ( $this->logger ) {
					$this->logger->log( 'Failed to write image file to disk', 'error' );
				}
				return false;
			}

			// Prepare file array for WordPress
			$file_array = array(
				'name'     => $filename,
				'tmp_name' => $file_path,
				'size'     => filesize( $file_path ),
			);

			// Insert the attachment
			$attachment_id = media_handle_sideload( $file_array, $post_id, $course_name . ' Featured Image' );

			if ( is_wp_error( $attachment_id ) ) {
				if ( $this->logger ) {
					$this->logger->log( 'Failed to create attachment: ' . $attachment_id->get_error_message(), 'error' );
				}
				// Clean up the temporary file
				if ( file_exists( $file_path ) ) {
					wp_delete_file( $file_path );
				}
				return false;
			}

			// Set as featured image
			$result = set_post_thumbnail( $post_id, $attachment_id );

			if ( $result ) {
				if ( $this->logger ) {
					$this->logger->log( 'Successfully set featured image for post ' . $post_id . ' (attachment ID: ' . $attachment_id . ')' );
				}
				return true;
			} else {
				if ( $this->logger ) {
					$this->logger->log( 'Failed to set post thumbnail for post ' . $post_id, 'error' );
				}
				return false;
			}
		} catch ( Exception $e ) {
			if ( $this->logger ) {
				$this->logger->log( 'Exception in set_featured_image: ' . $e->getMessage(), 'error' );
			}
			return false;
		}
	}

	/**
	 * Clean up temporary files
	 *
	 * @param string $file_path Path to temporary file
	 */
	private function cleanup_temp_file( $file_path ) {
		if ( file_exists( $file_path ) ) {
			wp_delete_file( $file_path );
		}
	}
}
