
<?php
/**
 * Utility functions for Canvas Course Sync
 *
 * @package Canvas_Course_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get current sync status
 *
 * @return array Sync status information
 */
function ccs_get_sync_status() {
    return get_transient('ccs_sync_status') ?: array(
        'is_running' => false,
        'message' => '',
        'progress' => array(
            'processed' => 0,
            'total' => 0,
            'imported' => 0,
            'skipped' => 0,
            'errors' => 0
        )
    );
}

/**
 * Update sync status
 *
 * @param string $message Status message
 * @param array $progress Progress data
 */
function ccs_update_sync_status($message, $progress = array()) {
    $status = array(
        'is_running' => true,
        'message' => $message,
        'progress' => wp_parse_args($progress, array(
            'processed' => 0,
            'total' => 0,
            'imported' => 0,
            'skipped' => 0,
            'errors' => 0
        ))
    );
    
    set_transient('ccs_sync_status', $status, 300); // 5 minutes
}

/**
 * Clear sync status
 */
function ccs_clear_sync_status() {
    delete_transient('ccs_sync_status');
}

/**
 * Register the courses custom post type
 */
function ccs_register_courses_post_type() {
    $labels = array(
        'name'                  => _x('Courses', 'Post type general name', 'canvas-course-sync'),
        'singular_name'         => _x('Course', 'Post type singular name', 'canvas-course-sync'),
        'menu_name'             => _x('Courses', 'Admin Menu text', 'canvas-course-sync'),
        'name_admin_bar'        => _x('Course', 'Add New on Toolbar', 'canvas-course-sync'),
        'add_new'               => __('Add New', 'canvas-course-sync'),
        'add_new_item'          => __('Add New Course', 'canvas-course-sync'),
        'new_item'              => __('New Course', 'canvas-course-sync'),
        'edit_item'             => __('Edit Course', 'canvas-course-sync'),
        'view_item'             => __('View Course', 'canvas-course-sync'),
        'all_items'             => __('All Courses', 'canvas-course-sync'),
        'search_items'          => __('Search Courses', 'canvas-course-sync'),
        'parent_item_colon'     => __('Parent Courses:', 'canvas-course-sync'),
        'not_found'             => __('No courses found.', 'canvas-course-sync'),
        'not_found_in_trash'    => __('No courses found in Trash.', 'canvas-course-sync'),
        'featured_image'        => _x('Course Image', 'Overrides the "Featured Image" phrase', 'canvas-course-sync'),
        'set_featured_image'    => _x('Set course image', 'Overrides the "Set featured image" phrase', 'canvas-course-sync'),
        'remove_featured_image' => _x('Remove course image', 'Overrides the "Remove featured image" phrase', 'canvas-course-sync'),
        'use_featured_image'    => _x('Use as course image', 'Overrides the "Use as featured image" phrase', 'canvas-course-sync'),
        'archives'              => _x('Course archives', 'The post type archive label used in nav menus', 'canvas-course-sync'),
        'insert_into_item'      => _x('Insert into course', 'Overrides the "Insert into post" phrase', 'canvas-course-sync'),
        'uploaded_to_this_item' => _x('Uploaded to this course', 'Overrides the "Uploaded to this post" phrase', 'canvas-course-sync'),
        'filter_items_list'     => _x('Filter courses list', 'Screen reader text for the filter links', 'canvas-course-sync'),
        'items_list_navigation' => _x('Courses list navigation', 'Screen reader text for the pagination', 'canvas-course-sync'),
        'items_list'            => _x('Courses list', 'Screen reader text for the items list', 'canvas-course-sync'),
    );

    $args = array(
        'labels'             => $labels,
        'public'             => true,
        'publicly_queryable' => true,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'query_var'          => true,
        'rewrite'            => array('slug' => 'courses'),
        'capability_type'    => 'post',
        'has_archive'        => true,
        'hierarchical'       => false,
        'menu_position'      => null,
        'menu_icon'          => 'dashicons-welcome-learn-more',
        'supports'           => array('title', 'editor', 'thumbnail', 'excerpt', 'custom-fields'),
        'show_in_rest'       => true,
    );

    register_post_type('courses', $args);
}
add_action('init', 'ccs_register_courses_post_type');

/**
 * Add metaboxes for course information
 */
function ccs_add_course_metaboxes() {
    add_meta_box(
        'ccs-course-link',
        __('Canvas Course Link', 'canvas-course-sync'),
        'ccs_course_link_metabox_callback',
        'courses',
        'side',
        'default'
    );
}
add_action('add_meta_boxes', 'ccs_add_course_metaboxes');

/**
 * Course link metabox callback
 *
 * @param WP_Post $post Current post object
 */
function ccs_course_link_metabox_callback($post) {
    $canvas_course_sync = canvas_course_sync();
    if ($canvas_course_sync && isset($canvas_course_sync->importer)) {
        $canvas_course_sync->importer->display_course_link_metabox($post);
    }
}
