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
 * Get list of excluded course titles
 *
 * @return array Array of course titles to exclude from sync
 */
function ccs_get_excluded_course_titles() {
    return array(
        'Engaging Communities for Systems Change',
        'Deaf 101',
        'Attitudes as Barriers for Deaf People',
        'Foundations of Effective Accommodation',
        'Instructional Strategies for Deaf Student Success',
        'Teaching Deaf Students Online',
        'Improving Campus Access',
        'Note Taker Training',
        'Developing Accessible Work Based Learning Programs',
        'Start and Build a Mentoring Program for Deaf Youth',
        'Designing Summer Programs for Deaf Youth',
        'Discovering System Barriers and Exploring the WHY',
        'Legal Frameworks and Responsibilities for Accessibility',
        'Accommodations 101',
        'Building Relationships with Deaf Communities',
        'Using UDL Principles for Teaching Deaf Students Online',
        'Introduction to Interpreting Services',
        'Captioned Media 101',
        'Introduction to Remote Services',
        'Speech to Text 101',
        'Designing Accessible Online Experiences for Deaf People',
        'Transforming Systems to Improve Experiences for Deaf People',
        'Testing Experiences for Deaf Students',
        'Coordinating Services for Deaf Students',
        'OnDemand Webinar: Commencement for All: Making Graduation Accessible',
        'OnDemand Webinar: What are Assistive Listening Systems?',
        'OnDemand Webinar: Preparing Access Services for Deaf College Students: Tips & Resources',
        'OnDemand Webinar: Does Auto Captioning Effectively Accommodate Deaf People?',
        'OnDemand Webinar: Deaf People Leading the Way',
        'OnDemand Webinar: For Deaf People, By Deaf People: Centering Deaf People in Systems Change',
        'OnDemand Webinar: Centralized Systems that Promote #DeafSuccess at Colleges',
        'OnDemand Webinar: Mentoring Deaf Youth Leads to #DeafSuccess',
        'Data-Driven Decision Making: Why Does it Matter?',
        'Supporting Accessible Learning Environments and Instruction for Deaf Students',
        'Introduction to Assistive Listening Devices and Systems',
        'Finding Data about Deaf People',
        'Collecting Data from the Community',
        'Work-Based Learning Programs',
        'Hosting Community Conversations Facilitated Course',
        'Improving Campus Access Facilitated Course',
        'Advanced Practices: Evaluating & Managing Services Using Data Facilitated Course',
        'OnDemand Webinar: Using Data to Further Dialogue for Change',
        'OnDemand Webinar: Pathways To and Through Health Science Education'
    );
}

/**
 * Check if a course title should be excluded from sync
 *
 * @param string $course_title The course title to check
 * @return bool True if course should be excluded, false otherwise
 */
function ccs_is_course_excluded($course_title) {
    if (empty($course_title)) {
        return false;
    }
    
    $excluded_titles = ccs_get_excluded_course_titles();
    $course_title_lower = strtolower(trim($course_title));
    
    foreach ($excluded_titles as $excluded_title) {
        $excluded_title_lower = strtolower(trim($excluded_title));
        
        // Check for exact match or partial match
        if ($course_title_lower === $excluded_title_lower || 
            strpos($course_title_lower, $excluded_title_lower) !== false ||
            strpos($excluded_title_lower, $course_title_lower) !== false) {
            return true;
        }
    }
    
    // Also exclude courses with "SHELL" or "Template" in the name
    if (stripos($course_title, 'SHELL') !== false || stripos($course_title, 'Template') !== false) {
        return true;
    }
    
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
    
    $course_key = 'id_' . intval($course_id);
    return isset($omitted_courses[$course_key]);
}

/**
 * Get list of omitted courses
 *
 * @return array Array of omitted course data
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
    
    $course_key = 'id_' . intval($course_id);
    if (isset($omitted_courses[$course_key])) {
        unset($omitted_courses[$course_key]);
        return update_option('ccs_omitted_courses', $omitted_courses);
    }
    
    return false;
}
