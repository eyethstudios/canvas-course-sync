
<?php
/**
 * Helper functions for Canvas Course Sync
 *
 * @package Canvas_Course_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get list of course titles to exclude from sync (DEPRECATED - replaced by catalog validation)
 * Kept for backward compatibility
 *
 * @deprecated Use CCS_Catalog_Validator instead
 * @return array Array of course titles to exclude
 */
function ccs_get_excluded_course_titles() {
    // Return empty array - exclusion now handled by catalog validation
    return array();
}

/**
 * Check if a course title should be excluded from sync (DEPRECATED)
 * Replaced by catalog validation in CCS_Catalog_Validator
 *
 * @deprecated Use CCS_Catalog_Validator::validate_against_catalog() instead
 * @param string $course_title The course title to check
 * @return bool Always returns false - exclusion now handled by catalog validation
 */
function ccs_is_course_excluded($course_title) {
    // Always return false - exclusion now handled by catalog validation
    return false;
}

/**
 * Check if a course ID is omitted by user selection
 *
 * @param int $course_id The Canvas course ID to check
 * @return bool True if course is omitted, false otherwise
 */
function ccs_is_course_omitted($course_id) {
    if (empty($course_id)) {
        return false;
    }
    
    $omitted_courses = get_option('ccs_omitted_courses', array());
    if (!is_array($omitted_courses)) {
        return false;
    }
    
    // Check if course ID exists in the omitted list (using simple array format)
    return in_array(intval($course_id), $omitted_courses);
}

/**
 * Get list of omitted courses
 *
 * @return array Array of omitted course IDs
 */
function ccs_get_omitted_courses() {
    $omitted_courses = get_option('ccs_omitted_courses', array());
    return is_array($omitted_courses) ? $omitted_courses : array();
}

/**
 * Remove a course from the omitted list
 *
 * @param int $course_id The Canvas course ID to remove from omitted list
 * @return bool True if removed successfully, false otherwise
 */
function ccs_remove_omitted_course($course_id) {
    if (empty($course_id)) {
        return false;
    }
    
    $omitted_courses = get_option('ccs_omitted_courses', array());
    if (!is_array($omitted_courses)) {
        return false;
    }
    
    $course_id = intval($course_id);
    $key = array_search($course_id, $omitted_courses);
    
    if ($key !== false) {
        unset($omitted_courses[$key]);
        // Re-index array to prevent gaps
        $omitted_courses = array_values($omitted_courses);
        return update_option('ccs_omitted_courses', $omitted_courses);
    }
    
    return false;
}
