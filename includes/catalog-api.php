<?php
/**
 * Catalog API Handler for Catalog Course Sync
 *
 * @package Catalog_Course_Sync
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define API constants
if ( ! defined( 'CCS_MAX_API_PAGES' ) ) {
	define( 'CCS_MAX_API_PAGES', 50 ); // Increased from 10 to handle more courses
}

if ( ! defined( 'CCS_API_TIMEOUT' ) ) {
	define( 'CCS_API_TIMEOUT', 30 );
}

if ( ! defined( 'CCS_FILE_DOWNLOAD_TIMEOUT' ) ) {
	define( 'CCS_FILE_DOWNLOAD_TIMEOUT', 60 );
}

/**
 * Catalog API class
 */
class CCS_Catalog_API {

	/**
	 * Catalog domain
	 */
	private $catalog_domain;

	/**
	 * Catalog API token
	 */
	private $catalog_token;

	/**
	 * Logger instance
	 */
	private $logger;

	/**
	 * @var Memory cache
	 */
	private $cache;

	/**
	 * Constructor with dependency injection
	 *
	 * @param CCS_Logger|null $logger Logger instance (optional)
	 */
	public function __construct( CCS_Logger $logger = null ) {
		$this->catalog_domain = get_option( 'ccs_catalog_url', CCS_DEFAULT_CATALOG_URL );
		$this->catalog_token  = get_option( 'ccs_catalog_token' );
		$this->logger         = $logger;
	}

	/**
	 * Make API request to Catalog (now public for use by other classes)
	 */
	public function make_request( $endpoint, $method = 'GET', $data = null ) {
		if ( empty( $this->catalog_domain ) || empty( $this->catalog_token ) ) {
			return new WP_Error( 'missing_credentials', __( 'Catalog API credentials not configured.', 'canvas-course-sync' ) );
		}

		// Return cached version if set
		if ( isset( $this->cache[ $endpoint ] ) && $method === 'GET' ) {
			return $this->cache;
		}

		// Ensure domain has proper format
		$domain = rtrim( $this->catalog_domain, '/' );
		if ( ! preg_match( '/^https?:\/\//', $domain ) ) {
			$domain = 'https://' . $domain;
		}

		$url = $domain . '/api/v1/' . ltrim( $endpoint, '/' );

		$args = array(
			'method'  => $method,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->catalog_token,
				'Content-Type'  => 'application/json',
			),
			'timeout' => CCS_API_TIMEOUT,
		);

		if ( $data && $method !== 'GET' ) {
			$args['body'] = wp_json_encode( $data );
		}

		if ( $this->logger ) {
			$this->logger->log( 'Making Catalog API request to: ' . $url );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			if ( $this->logger ) {
				$this->logger->log( 'Catalog API request failed: ' . $response->get_error_message(), 'error' );
			}
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		if ( $response_code >= 400 ) {
			$error_message = sprintf( __( 'Catalog API returned error %1$d: %2$s', 'canvas-course-sync' ), $response_code, $response_body );
			if ( $this->logger ) {
				$this->logger->log( $error_message, 'error' );
			}
			return new WP_Error( 'api_error', $error_message );
		}

		$decoded = json_decode( $response_body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$error_message = __( 'Invalid JSON response from Catalog API', 'canvas-course-sync' );
			if ( $this->logger ) {
				$this->logger->log( $error_message, 'error' );
			}
			return new WP_Error( 'invalid_json', $error_message );
		}

		// Return both data and headers for pagination
		return $this->cache[ $endpoint ] = array(
			'data'    => $decoded,
			'headers' => wp_remote_retrieve_headers( $response ),
		);
	}

	/**
	 * Test connection to Catalog API
	 */
	public function test_connection() {
		$result = $this->make_request( 'courses' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( $this->logger ) {
			$this->logger->log( 'Catalog API connection test successful' );
		}

		return true;
	}

	/**
	 * Get courses from Catalog
	 */
	public function get_courses( $per_page = 100 ) {
		$all_courses = array();
		$page        = 1;
		$max_pages   = CCS_MAX_API_PAGES; // Safety limit

		error_log( 'CCS_Catalog_API: Starting get_courses() with per_page=' . $per_page . ', max_pages=' . $max_pages );

		do {
			// Try different endpoint parameters for better results
			$endpoint = "courses?per_page={$per_page}&page={$page}";
			error_log( 'CCS_Catalog_API: Requesting page ' . $page . ' with endpoint: ' . $endpoint );

			$result = $this->make_request( $endpoint );

			if ( is_wp_error( $result ) ) {
				error_log( 'CCS_Catalog_API: ERROR on page ' . $page . ': ' . $result->get_error_message() );
				return $result;
			}

			$courses = $result['data']['courses'];
			$headers = $result['headers'];

			error_log( 'CCS_Catalog_API: Page ' . $page . ' returned ' . ( is_array( $courses ) ? count( $courses ) : 0 ) . ' courses' );
			error_log( 'CCS_Catalog_API: Response headers: ' . print_r( $headers, true ) );

			if ( empty( $courses ) || ! is_array( $courses ) ) {
				error_log( 'CCS_Catalog_API: Breaking - no courses returned on page ' . $page );
				break;
			}

			$all_courses = array_merge( $all_courses, $courses );

			if ( $this->logger ) {
				$this->logger->log( 'Retrieved page ' . $page . ' with ' . count( $courses ) . ' courses (total so far: ' . count( $all_courses ) . ')' );
			}

			// Check if there's a next page - improved logic
			$has_next_page = false;

			// First, check if we got a full page (indicating there might be more)
			if ( count( $courses ) == $per_page ) {
				error_log( 'CCS_Catalog_API: Got full page (' . $per_page . ' courses), likely more pages available' );
				$has_next_page = true;
			}

			// Also check Link header if available
			if ( isset( $headers['link'] ) ) {
				$link_header = $headers['link'];
				if ( is_array( $link_header ) ) {
					$link_header = implode( ', ', $link_header );
				}
				$link_has_next = strpos( $link_header, 'rel="next"' ) !== false;
				error_log( 'CCS_Catalog_API: Link header: ' . $link_header );
				error_log( 'CCS_Catalog_API: Link header indicates next page: ' . ( $link_has_next ? 'YES' : 'NO' ) );

				// Use Link header result if it suggests no more pages
				if ( ! $link_has_next ) {
					$has_next_page = false;
					error_log( 'CCS_Catalog_API: Link header says no more pages, stopping' );
				}
			} else {
				error_log( 'CCS_Catalog_API: No link header found' );
			}

			++$page;
			error_log( 'CCS_Catalog_API: Moving to page ' . $page . ', has_next_page=' . ( $has_next_page ? 'true' : 'false' ) . ', within max_pages=' . ( $page <= $max_pages ? 'true' : 'false' ) );

		} while ( $has_next_page && $page <= $max_pages );

		error_log( 'CCS_Catalog_API: Final result: ' . count( $all_courses ) . ' total courses retrieved across ' . ( $page - 1 ) . ' pages' );

		if ( $this->logger ) {
			$this->logger->log( 'Retrieved ' . count( $all_courses ) . ' total courses from Catalog API across ' . ( $page - 1 ) . ' pages' );
		}

		// Sort $all_courses by the 'title' key before returning
		usort(
			$all_courses,
			function ( $a, $b ) {
				$titleA = isset( $a['title'] ) ? $a['title'] : '';
				$titleB = isset( $b['title'] ) ? $b['title'] : '';
				return strcasecmp( $titleA, $titleB );
			}
		);

		return $all_courses;
	}

	/**
	 * Get course details by Catalog ID
	 */
	public function get_course_by_catalog_id( $catalog_id ) {
		if ( $this->logger ) {
			$this->logger->log( 'Fetching course from Catalog API by Catalog ID: ' . $catalog_id );
		}
		error_log( 'CCS_Catalog_API: Starting get_course_by_catalog_id() for Catalog ID: ' . $catalog_id );

		$endpoint = "courses/{$catalog_id}";
		error_log( 'CCS_Catalog_API: Requesting endpoint: ' . $endpoint );

		$result = $this->make_request( $endpoint );

		if ( is_wp_error( $result ) ) {
			error_log( 'CCS_Catalog_API: ERROR: ' . $result->get_error_message() );
			return $result;
		}

		if ( $this->logger ) {
			$this->logger->log( 'Found course with Catalog ID: ' . $catalog_id );
		}

		return $result['data']['course'];
	}

	/**
	 * Get course details by Canvas ID (not Catalog ID)
	 */
	public function get_course_by_canvas_id( $canvas_id ) {
		if ( $this->logger ) {
			$this->logger->log( 'Searching for course with Canvas ID: ' . $canvas_id );
		}
		error_log( 'CCS_Catalog_API: Starting get_course_by_canvas_id() for Canvas ID: ' . $canvas_id );

		$courses = $this->get_courses();
		if ( is_wp_error( $courses ) ) {
			error_log( 'CCS_Catalog_API: ERROR retrieving courses: ' . $courses->get_error_message() );
			return $courses;
		}

		foreach ( $courses as $course ) {
			if ( isset( $course['canvas_course']['id'] ) && $course['canvas_course']['id'] == $canvas_id ) {
				error_log( 'CCS_Catalog_API: Found course with Canvas ID: ' . $canvas_id );
				if ( $this->logger ) {
					$this->logger->log( 'Found course with Canvas ID: ' . $canvas_id );
				}
				return $course;
			}
		}

		$msg = 'Course with Canvas ID ' . $canvas_id . ' not found in Catalog.';
		error_log( 'CCS_Catalog_API: ' . $msg );
		if ( $this->logger ) {
			$this->logger->log( $msg, 'warning' );
		}
		return new WP_Error( 'not_found', __( $msg, 'canvas-course-sync' ) );
	}
}
