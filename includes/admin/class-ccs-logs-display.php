
<?php
/**
 * Canvas Course Sync Logs Display Component
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class CCS_Logs_Display {
    /**
     * Logger instance
     *
     * @var CCS_Logger
     */
    private $logger;

    /**
     * Constructor
     *
     * @param CCS_Logger $logger Logger instance
     */
    public function __construct($logger) {
        $this->logger = $logger;
    }

    /**
     * Render logs section
     */
    public function render() {
        ?>
        <div class="ccs-panel">
            <h2><?php _e('Sync Logs', 'canvas-course-sync'); ?></h2>
            <button id="ccs-clear-logs" class="button button-secondary ccs-clear-logs">
                <?php _e('Clear Logs', 'canvas-course-sync'); ?>
            </button>
            <div class="ccs-log-container">
            <?php
            $recent_logs = $this->logger->get_recent_logs(20);
            if (!empty($recent_logs)) :
                foreach ($recent_logs as $log_entry) : 
                    $entry_class = '';
                    if (strpos($log_entry, '[ERROR]') !== false) {
                        $entry_class = 'ccs-log-error';
                    } elseif (strpos($log_entry, '[WARNING]') !== false) {
                        $entry_class = 'ccs-log-warning';
                    }
                    echo '<div class="ccs-log-entry"><pre class="' . $entry_class . '">' . esc_html($log_entry) . '</pre></div>';
                endforeach;
            else : 
                echo '<p>' . __('No logs available yet.', 'canvas-course-sync') . '</p>';
            endif;
            ?>
            </div>
            
            <p>
                <?php 
                $log_file = $this->logger->get_log_file();
                $log_url = '';
                $upload_dir = wp_upload_dir();
                if (file_exists($log_file)) {
                    $log_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $log_file);
                    echo '<a href="' . esc_url($log_url) . '" target="_blank" class="button button-secondary">';
                    _e('View Full Log', 'canvas-course-sync');
                    echo '</a>';
                }
                ?>
            </p>
        </div>
        <?php
    }
}
