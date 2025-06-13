
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

// Include handlers only if the files exist
$handlers_dir = plugin_dir_path(__FILE__) . 'handlers/';
if (file_exists($handlers_dir . 'index.php')) {
    require_once $handlers_dir . 'index.php';
}
if (file_exists($handlers_dir . 'class-ccs-content-handler.php')) {
    require_once $handlers_dir . 'class-ccs-content-handler.php';
}
if (file_exists($handlers_dir . 'class-ccs-media-handler.php')) {
    require_once $handlers_dir . 'class-ccs-media-handler.php';
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
     * Content Handler instance
     *
     * @var CCS_Content_Handler
     */
    private $content_handler;

    /**
     * Media Handler instance
     *
     * @var CCS_Media_Handler
     */
    private $media_handler;

    /**
     * Constructor
     */
    public function __construct() {
        $canvas_course_sync = canvas_course_sync();
        $this->logger = ($canvas_course_sync && isset($canvas_course_sync->logger)) ? $canvas_course_sync->logger : new CCS_Logger();
        $this->api = ($canvas_course_sync && isset($canvas_course_sync->api)) ? $canvas_course_sync->api : new CCS_Canvas_API();
        
        // Initialize handlers if classes exist
        if (class_exists('CCS_Content_Handler')) {
            $this->content_handler = new CCS_Content_Handler();
        }
        if (class_exists('CCS_Media_Handler')) {
            $this->media_handler = new CCS_Media_Handler();
        }
    }

    /**
     * Import specific courses from Canvas
     *
     * @param array $course_ids Array of Canvas course IDs to import
     * @return array Result of import process
     */
    public function import_courses($course_ids = array()) {
        // Make sure $course_ids is an array before trying to count it
        if (!is_array($course_ids)) {
            $this->logger->log('Course IDs must be an array, ' . gettype($course_ids) . ' given', 'error');
            return array(
                'imported' => 0,
                'skipped'  => 0,
                'errors'   => 1,
                'total'    => 0,
                'message'  => 'Invalid course IDs format'
            );
        }

        $this->logger->log('Starting course import process for ' . count($course_ids) . ' selected courses');
        ccs_clear_sync_status();
        
        $imported = 0;
        $skipped = 0;
        $errors = 0;
        $processed = 0;
        $total_courses = count($course_ids);

        foreach ($course_ids as $course_id) {
            $processed++;
            ccs_update_sync_status(
                sprintf('Processing course %d of %d...', $processed, $total_courses),
                array(
                    'processed' => $processed,
                    'total' => $total_courses,
                    'imported' => $imported,
                    'skipped' => $skipped,
                    'errors' => $errors
                )
            );
            
            $this->logger->log('Fetching details for course ID: ' . $course_id);
            $course_details = $this->api->get_course_details($course_id);
            
            if (is_wp_error($course_details)) {
                $this->logger->log('Failed to get details for course ' . $course_id . ': ' . $course_details->get_error_message(), 'error');
                $errors++;
                continue;
            }
            
            $course_name = $course_details->name;
            
            $this->logger->log('Processing course: ' . $course_name . ' (ID: ' . $course_id . ')');
            
            // First, check if we have a post with this Canvas ID already
            $existing_by_id = get_posts(array(
                'post_type'      => 'courses',
                'post_status'    => array('draft', 'publish', 'private', 'pending'),
                'posts_per_page' => 1,
                'meta_key'       => 'canvas_course_id',
                'meta_value'     => $course_id,
                'fields'         => 'ids',
            ));

            if (!empty($existing_by_id)) {
                $post_id = $existing_by_id[0];
                $this->logger->log('Found existing course with Canvas ID metadata. Post ID: ' . $post_id . '. Skipping import.');
                $skipped++;
                continue;
            }
            
            // If no match by ID, check by title
            $existing_by_title = get_posts(array(
                'post_type'      => 'courses',
                'post_status'    => array('draft', 'publish', 'private', 'pending'),
                'title'          => $course_name,
                'posts_per_page' => 1,
                'fields'         => 'ids',
            ));
            
            if (!empty($existing_by_title)) {
                $post_id = $existing_by_title[0];
                $this->logger->log('Found existing course with ID: ' . $post_id . '. Skipping import.');
                
                // Maybe update existing Canvas course ID if not present
                $existing_canvas_id = get_post_meta($post_id, 'canvas_course_id', true);
                if (empty($existing_canvas_id)) {
                    update_post_meta($post_id, 'canvas_course_id', $course_id);
                    $this->logger->log('Added missing Canvas course ID metadata to existing post.');
                }
                
                $skipped++;
                continue;
            }
            
            // Process content using the content handler if available
            $post_content = '';
            if ($this->content_handler) {
                $post_content = $this->content_handler->prepare_course_content($course_details);
            } else {
                // Fallback content preparation
                $post_content = $this->prepare_basic_course_content($course_details);
            }
            
            // For debugging
            $this->logger->log('Post content length: ' . strlen($post_content) . ' characters');
            if (empty($post_content)) {
                $this->logger->log('Warning: No content found for course', 'warning');
            }
            
            // Create new post
            $post_id = wp_insert_post(array(
                'post_title'   => $course_name ?? '',
                'post_status'  => 'draft',
                'post_type'    => 'courses',
                'post_content' => $post_content,
            ));
            
            if (is_wp_error($post_id) || !$post_id) {
                $this->logger->log('Failed to create post for course ' . $course_id . ': ' . (is_wp_error($post_id) ? $post_id->get_error_message() : 'Unknown error'), 'error');
                $errors++;
                continue;
            }
            
            // Save marker meta for future lookups
            update_post_meta($post_id, 'canvas_course_id', $course_id);
            
            // Set course link
            $canvas_domain = $this->api->get_domain();
            if (!empty($canvas_domain) && !empty($course_id)) {
                $canvas_course_link = trailingslashit($canvas_domain) . 'courses/' . $course_id;
                update_post_meta($post_id, 'link', esc_url_raw($canvas_course_link));
            }
            
            // Handle featured image using the media handler if available
            if ($this->media_handler && !empty($course_details->image_download_url)) {
                $this->logger->log('Course has image at URL: ' . $course_details->image_download_url);
                
                $result = $this->media_handler->set_featured_image($post_id, $course_details->image_download_url, $course_name);
                
                if ($result) {
                    $this->logger->log('Successfully set featured image for course');
                } else {
                    $this->logger->log('Failed to set featured image for course', 'warning');
                }
            }
            
            $imported++;
            $this->logger->log('Successfully imported course: ' . $course_name);
        }

        $final_status = sprintf(
            'Import complete. Processed %d courses (%d imported, %d skipped, %d errors)',
            $total_courses,
            $imported,
            $skipped,
            $errors
        );
        
        $this->logger->log($final_status);
        ccs_clear_sync_status();

        return array(
            'imported' => $imported,
            'skipped'  => $skipped,
            'errors'   => $errors,
            'total'    => $total_courses,
            'message'  => $final_status
        );
    }

    /**
     * Prepare basic course content when content handler is not available
     *
     * @param object $course_details Course details from Canvas
     * @return string Prepared content
     */
    private function prepare_basic_course_content($course_details) {
        $content = '';
        
        if (!empty($course_details->course_code)) {
            $content .= '<p><strong>Course Code:</strong> ' . esc_html($course_details->course_code) . '</p>';
        }
        
        if (!empty($course_details->term) && !empty($course_details->term->name)) {
            $content .= '<p><strong>Term:</strong> ' . esc_html($course_details->term->name) . '</p>';
        }
        
        if (!empty($course_details->total_students)) {
            $content .= '<p><strong>Total Students:</strong> ' . intval($course_details->total_students) . '</p>';
        }
        
        if (!empty($course_details->created_at)) {
            $content .= '<p><strong>Created:</strong> ' . esc_html($course_details->created_at) . '</p>';
        }
        
        return $content;
    }

    /**
     * Display course link metabox
     *
     * @param WP_Post $post Current post object
     */
    public function display_course_link_metabox($post) {
        $link = get_post_meta($post->ID, 'link', true);
        if (empty($link)) {
            echo '<p>' . esc_html__('No Canvas course link available.', 'canvas-course-sync') . '</p>';
            return;
        }
        ?>
        <p>
            <a href="<?php echo esc_url($link); ?>" target="_blank">
                <?php esc_html_e('View in Canvas', 'canvas-course-sync'); ?>
            </a>
        </p>
        <?php
    }
}
