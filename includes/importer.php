<?php
/**
 * Handles importing courses from Canvas into WP.
 *
 * @package Canvas_Course_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Importer class
 */
class CCS_Importer {
    /**
     * Logger instance
     *
     * @var CCS_Logger
     */
    private $logger;

    /**
     * Canvas API instance
     *
     * @var CCS_Canvas_API
     */
    private $api;

    /**
     * Constructor
     */
    public function __construct() {
        global $canvas_course_sync;
        $this->logger = $canvas_course_sync->logger ?? new CCS_Logger();
        $this->api = $canvas_course_sync->api ?? new CCS_Canvas_API();
    }

    /**
     * Import courses from Canvas
     *
     * @return array Result of import process
     */
    public function import_courses() {
        $this->logger->log('Starting course import process');
        
        $canvas_courses = $this->api->get_courses();
        if (is_wp_error($canvas_courses)) {
            $this->logger->log('Failed to fetch courses from Canvas API: ' . $canvas_courses->get_error_message(), 'error');
            return array(
                'imported' => 0,
                'skipped' => 0,
                'errors' => 1
            );
        }

        $imported = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($canvas_courses as $canvas_course) {
            $course_id = $canvas_course->id;
            $course_name = $canvas_course->name;
            
            $this->logger->log('Processing course: ' . $course_name . ' (ID: ' . $course_id . ')');

            // Example course import code (pseudo-code — use actual import logic as in your file)
            $args = array(
                'post_title'   => $canvas_course->name ?? '',
                'post_status'  => 'publish',
                'post_type'    => 'courses',
                // ... any other args
            );

            // Check if already exists, update or insert as needed
            $existing = get_posts(array(
                'post_type' => 'courses',
                'meta_key' => 'canvas_course_id',
                'meta_value' => $canvas_course->id,
                'posts_per_page' => 1,
                'fields' => 'ids',
            ));

            if ($existing && count($existing) > 0) {
                $post_id = $existing[0];
                wp_update_post(array_merge($args, array('ID' => $post_id)));
            } else {
                $post_id = wp_insert_post($args);
                if (is_wp_error($post_id) || !$post_id) {
                    $errors++;
                    continue;
                }
                // Save marker meta for future lookups
                update_post_meta($post_id, 'canvas_course_id', $canvas_course->id);
            }

            // SET (UPDATE) THE LINK META FIELD WITH THE CANVAS COURSE LINK
            // Assumes a standard Canvas course URL structure
            $canvas_domain = $this->api->get_domain();
            if (!empty($canvas_domain) && !empty($canvas_course->id)) {
                $canvas_course_link = trailingslashit($canvas_domain) . 'courses/' . $canvas_course->id;
                update_post_meta($post_id, 'link', esc_url_raw($canvas_course_link));
            }
            
            $imported++;
        }

        return array(
            'imported' => $imported,
            'skipped'  => $skipped,
            'errors'   => $errors,
        );
    }

    /**
     * Display course link metabox
     * 
     * @param WP_Post $post The post object
     */
    public function display_course_link_metabox($post) {
        $canvas_link = get_post_meta($post->ID, 'link', true);
        echo '<p><strong>Canvas Course Link:</strong> ';
        if (!empty($canvas_link)) {
            echo '<a href="' . esc_url($canvas_link) . '" target="_blank" rel="noopener noreferrer">' . esc_html($canvas_link) . '</a>';
        } else {
            echo 'No link available.';
        }
        echo '</p>';
    }
}
