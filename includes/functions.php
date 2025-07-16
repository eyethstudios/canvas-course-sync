<?php
/**
 * Helper functions for Canvas Course Sync
 *
 * @package Canvas_Course_Sync
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check if a course ID is omitted by user selection
 *
 * @param int $course_id The Canvas course ID to check
 * @return bool True if course is omitted, false otherwise
 */
function ccs_is_course_omitted( $course_id ) {
	if ( empty( $course_id ) ) {
		return false;
	}

	$omitted_courses = get_option( 'ccs_omitted_courses', array() );
	if ( ! is_array( $omitted_courses ) ) {
		return false;
	}

	// Check if course ID exists in the omitted list (using simple array format)
	return in_array( intval( $course_id ), $omitted_courses );
}

/**
 * Get list of omitted courses
 *
 * @return array Array of omitted course IDs
 */
function ccs_get_omitted_courses() {
	$omitted_courses = get_option( 'ccs_omitted_courses', array() );
	return is_array( $omitted_courses ) ? $omitted_courses : array();
}

/**
 * Remove a course from the omitted list
 *
 * @param int $course_id The Canvas course ID to remove from omitted list
 * @return bool True if removed successfully, false otherwise
 */
function ccs_remove_omitted_course( $course_id ) {
	if ( empty( $course_id ) ) {
		return false;
	}

	$omitted_courses = get_option( 'ccs_omitted_courses', array() );
	if ( ! is_array( $omitted_courses ) ) {
		return false;
	}

	$course_id = intval( $course_id );
	$key       = array_search( $course_id, $omitted_courses );

	if ( $key !== false ) {
		unset( $omitted_courses[ $key ] );
		// Re-index array to prevent gaps
		$omitted_courses = array_values( $omitted_courses );
		return update_option( 'ccs_omitted_courses', $omitted_courses );
	}

	return false;
}
