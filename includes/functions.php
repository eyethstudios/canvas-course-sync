
<?php
/**
 * General functions for Canvas Course Sync
 *
 * @package Canvas_Course_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register custom post type for courses
 */
function ccs_register_courses_post_type() {
    $labels = array(
        'name' => __('Canvas Courses', 'canvas-course-sync'),
        'singular_name' => __('Canvas Course', 'canvas-course-sync'),
        'menu_name' => __('Canvas Courses', 'canvas-course-sync'),
        'name_admin_bar' => __('Canvas Course', 'canvas-course-sync'),
        'add_new' => __('Add New', 'canvas-course-sync'),
        'add_new_item' => __('Add New Canvas Course', 'canvas-course-sync'),
        'new_item' => __('New Canvas Course', 'canvas-course-sync'),
        'edit_item' => __('Edit Canvas Course', 'canvas-course-sync'),
        'view_item' => __('View Canvas Course', 'canvas-course-sync'),
        'all_items' => __('All Canvas Courses', 'canvas-course-sync'),
        'search_items' => __('Search Canvas Courses', 'canvas-course-sync'),
        'parent_item_colon' => __('Parent Canvas Courses:', 'canvas-course-sync'),
        'not_found' => __('No Canvas courses found.', 'canvas-course-sync'),
        'not_found_in_trash' => __('No Canvas courses found in Trash.', 'canvas-course-sync')
    );

    $args = array(
        'labels' => $labels,
        'public' => true,
        'publicly_queryable' => true,
        'show_ui' => true,
        'show_in_menu' => true,
        'query_var' => true,
        'rewrite' => array('slug' => 'courses'),
        'capability_type' => 'post',
        'has_archive' => true,
        'hierarchical' => false,
        'menu_position' => null,
        'supports' => array('title', 'editor', 'thumbnail', 'custom-fields'),
        'menu_icon' => 'dashicons-welcome-learn-more'
    );

    register_post_type('courses', $args);
}
add_action('init', 'ccs_register_courses_post_type');

/**
 * Get plugin instance
 */
function ccs_get_instance() {
    return canvas_course_sync();
}

/**
 * Check if Canvas credentials are set
 */
function ccs_has_credentials() {
    $domain = get_option('ccs_canvas_domain');
    $token = get_option('ccs_canvas_token');
    
    return !empty($domain) && !empty($token);
}

/**
 * Get Canvas domain
 */
function ccs_get_canvas_domain() {
    return get_option('ccs_canvas_domain', '');
}

/**
 * Get Canvas token (masked for display)
 */
function ccs_get_canvas_token_masked() {
    $token = get_option('ccs_canvas_token', '');
    if (empty($token)) {
        return '';
    }
    
    return substr($token, 0, 8) . '...' . substr($token, -4);
}
