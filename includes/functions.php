
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
        'message' => sanitize_text_field($message),
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
 * Check if user can manage Canvas sync
 *
 * @return bool Whether user can manage sync
 */
function ccs_user_can_manage_sync() {
    return current_user_can('manage_options') || current_user_can('edit_posts');
}

/**
 * Sanitize Canvas domain
 *
 * @param string $domain Domain to sanitize
 * @return string Sanitized domain
 */
function ccs_sanitize_canvas_domain($domain) {
    $domain = esc_url_raw($domain);
    $domain = untrailingslashit($domain);
    
    // Ensure it's a valid URL
    if (!filter_var($domain, FILTER_VALIDATE_URL)) {
        return '';
    }
    
    return $domain;
}

/**
 * Get Canvas course link
 *
 * @param int $post_id Post ID
 * @return string Canvas course link or empty string
 */
function ccs_get_canvas_course_link($post_id) {
    $canvas_id = get_post_meta($post_id, 'canvas_course_id', true);
    $canvas_domain = get_option('ccs_canvas_domain');
    
    if (empty($canvas_id) || empty($canvas_domain)) {
        return '';
    }
    
    return trailingslashit($canvas_domain) . 'courses/' . intval($canvas_id);
}

/**
 * Plugin activation hook
 */
function ccs_activate_plugin() {
    // Set flag to flush rewrite rules
    add_option('ccs_flush_rewrite_rules', true);
    
    // Create upload directory
    $upload_dir = wp_upload_dir();
    $log_dir = $upload_dir['basedir'] . '/canvas-course-sync/logs';
    
    if (!file_exists($log_dir)) {
        wp_mkdir_p($log_dir);
        
        // Add .htaccess for security
        $htaccess_content = "deny from all\n";
        file_put_contents($log_dir . '/.htaccess', $htaccess_content);
    }
    
    // Set default options
    add_option('ccs_version', CCS_VERSION);
    
    do_action('ccs_plugin_activated');
}

/**
 * Plugin deactivation hook
 */
function ccs_deactivate_plugin() {
    // Clear scheduled events
    wp_clear_scheduled_hook('ccs_auto_sync');
    
    // Clear transients
    delete_transient('ccs_sync_status');
    
    // Flush rewrite rules
    flush_rewrite_rules();
    
    do_action('ccs_plugin_deactivated');
}

/**
 * Register the courses custom post type
 */
function ccs_register_courses_post_type() {
    // Check if we should register the post type
    if (!apply_filters('ccs_register_post_type', true)) {
        return;
    }

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
        'rewrite'            => array(
            'slug' => apply_filters('ccs_post_type_slug', 'courses'),
            'with_front' => false
        ),
        'capability_type'    => 'post',
        'capabilities'       => array(
            'create_posts' => 'edit_posts', // Allow creation
        ),
        'map_meta_cap'       => true,
        'has_archive'        => true,
        'hierarchical'       => false,
        'menu_position'      => 25, // After Comments
        'menu_icon'          => 'dashicons-welcome-learn-more',
        'supports'           => array('title', 'editor', 'thumbnail', 'excerpt', 'custom-fields', 'revisions'),
        'show_in_rest'       => true,
        'rest_base'          => 'courses',
        'rest_controller_class' => 'WP_REST_Posts_Controller',
    );

    // Allow filtering of post type args
    $args = apply_filters('ccs_post_type_args', $args);

    register_post_type('courses', $args);
    
    // Flush rewrite rules if needed
    if (get_option('ccs_flush_rewrite_rules')) {
        flush_rewrite_rules();
        delete_option('ccs_flush_rewrite_rules');
    }
}
add_action('init', 'ccs_register_courses_post_type');

/**
 * Add metaboxes for course information
 */
function ccs_add_course_metaboxes() {
    // Check if we're on the correct post type
    $screen = get_current_screen();
    if (!$screen || $screen->post_type !== 'courses') {
        return;
    }

    add_meta_box(
        'ccs-course-info',
        __('Canvas Course Information', 'canvas-course-sync'),
        'ccs_course_info_metabox_callback',
        'courses',
        'side',
        'default'
    );
}
add_action('add_meta_boxes', 'ccs_add_course_metaboxes');

/**
 * Course information metabox callback
 *
 * @param WP_Post $post Current post object
 */
function ccs_course_info_metabox_callback($post) {
    // Security nonce
    wp_nonce_field('ccs_course_meta_nonce', 'ccs_course_meta_nonce');
    
    // Display Canvas course ID if available
    $canvas_id = get_post_meta($post->ID, 'canvas_course_id', true);
    if ($canvas_id) {
        echo '<p><strong>' . esc_html__('Canvas Course ID:', 'canvas-course-sync') . '</strong> ' . esc_html($canvas_id) . '</p>';
        
        $canvas_link = ccs_get_canvas_course_link($post->ID);
        if ($canvas_link) {
            echo '<p><a href="' . esc_url($canvas_link) . '" target="_blank">' . esc_html__('View in Canvas', 'canvas-course-sync') . '</a></p>';
        }
    }
}

/**
 * Save course meta data
 *
 * @param int $post_id Post ID
 */
function ccs_save_course_meta($post_id) {
    // Security checks
    if (!isset($_POST['ccs_course_meta_nonce']) || 
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ccs_course_meta_nonce'])), 'ccs_course_meta_nonce')) {
        return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // Only for courses post type
    if (get_post_type($post_id) !== 'courses') {
        return;
    }

    // Save custom fields if needed
    do_action('ccs_save_course_meta', $post_id);
}
add_action('save_post', 'ccs_save_course_meta');
